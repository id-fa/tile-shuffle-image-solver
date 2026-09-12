<?php
error_reporting(E_ALL);

/**
 * Generic tile shuffle solver (rows x cols), no rotation.
 * PHP 8.2+ safe (no dynamic properties)
 *
 * Usage:
 *  Solve:
 *   php tile_shuffle_solver.php shuffle.jpg --rows 4 --cols 4 --wm 6 --hm 4 --beam 1200 --cand 40 --dump-map mapping.txt --out solved.png
 *
 *  Rebuild from mapping:
 *   php tile_shuffle_solver.php shuffle.jpg --rows 4 --cols 4 --wm 6 --hm 4 --map mapping.txt --out solved.png
 *
 * Options:
 *   --score mgc|raw   edge compatibility measure (default: mgc)
 *                     mgc = Mahalanobis Gradient Compatibility (gradient prediction across the seam,
 *                           normalised by the gradient variance inside the tile)
 *                     raw = plain L1 pixel difference over a band of --band px (legacy behaviour)
 *   --norm 1|0        normalise each dissimilarity by the second-best match of the two tiles (default: 1)
 *   --orders 1..4     number of traversal orders tried by the beam search (default: 4)
 *   --refine 1|0      local refinement after the search: toroidal shifts, row/column shifts,
 *                     pairwise swaps (default: 1)
 *   --step N          sampling interval along the seam in px (default: 1)
 *   --band N          seam width in px for --score raw (default: 3; mgc always uses 2)
 *   --verbose         progress on stderr
 */

class TileSolver
{
    /* ---- configuration ---- */
    public int $rows;
    public int $cols;
    public int $wm;
    public int $hm;

    public int $band = 3;
    public int $step = 1;
    public int $beam = 1200;
    public int $cand = 40;
    public string $score = 'mgc';
    public bool $norm = true;
    public int $orders = 4;
    public bool $refine = true;
    public bool $verbose = false;

    private const INF = 1e15;
    private const MGC_EPS = 1.0;

    /* ---- image / geometry ---- */
    private GdImage $img;
    private int $W;
    private int $H;

    private int $tileW;
    private int $tileH;

    public array $xs = [];
    public array $ys = [];

    private array $tiles = [];
    private int $n;

    /* ---- compatibility tables ---- */
    private array $costR = [];   // [left][right]
    private array $costD = [];   // [top][bottom]

    public function __construct(int $rows, int $cols, int $wm, int $hm)
    {
        $this->rows = $rows;
        $this->cols = $cols;
        $this->wm = $wm;
        $this->hm = $hm;
        $this->n = $rows * $cols;

        if ($this->n > 60) {
            throw new Exception("Too many tiles ({$this->n}). Max supported is 60.");
        }
    }

    /* ---------- load & cut ---------- */

    public function load(string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'jpg' || $ext === 'jpeg') {
            $img = imagecreatefromjpeg($path);
        } elseif ($ext === 'png') {
            $img = imagecreatefrompng($path);
            // PNG の alpha を保持
            imagealphablending($img, false);
            imagesavealpha($img, true);
        } else {
            throw new Exception("unsupported image format: .$ext");
        }

        if (!$img) throw new Exception("failed to load image");

        $this->img = $img;
        $this->W = imagesx($img);
        $this->H = imagesy($img);

        // cell boundaries (round-based, no drift)
        for ($c = 0; $c <= $this->cols; $c++) {
            $this->xs[$c] = (int)round($c * $this->W / $this->cols);
        }
        for ($r = 0; $r <= $this->rows; $r++) {
            $this->ys[$r] = (int)round($r * $this->H / $this->rows);
        }

        // common tile size (after trimming)
        $minW = PHP_INT_MAX;
        $minH = PHP_INT_MAX;
        for ($c = 0; $c < $this->cols; $c++) {
            $minW = min($minW, $this->xs[$c + 1] - $this->xs[$c]);
        }
        for ($r = 0; $r < $this->rows; $r++) {
            $minH = min($minH, $this->ys[$r + 1] - $this->ys[$r]);
        }

        $this->tileW = max(1, $minW - $this->wm);
        $this->tileH = max(1, $minH - $this->hm);
    }

    public function cutTiles(): void
    {
        $tiles = [];
        for ($r=0; $r<$this->rows; $r++) {
            for ($c=0; $c<$this->cols; $c++) {
                $x0 = $this->xs[$c];
                $y0 = $this->ys[$r];
                $cw = $this->xs[$c+1] - $x0;
                $ch = $this->ys[$r+1] - $y0;

                $sx = $x0 + (int)floor($this->wm / 2);
                $sy = $y0 + (int)floor($this->hm / 2);
                $sw = $cw - $this->wm;
                $sh = $ch - $this->hm;

                if ($sw <= 0 || $sh <= 0) {
                    throw new Exception("wm/hm too large: tile crop becomes non-positive");
                }

                $t = imagecreatetruecolor($this->tileW, $this->tileH);
                imagealphablending($t, false);
                imagesavealpha($t, true);

                imagecopyresampled(
                    $t, $this->img,
                    0, 0, $sx, $sy,
                    $this->tileW, $this->tileH,
                    $sw, $sh
                );
                $tiles[] = $t;
            }
        }
        $this->tiles = $tiles;
    }

    /* ---------- mapping ---------- */

    public function readMappingFile(string $path): array
    {
        $txt = file_get_contents($path);
        if ($txt === false) throw new Exception("cannot read mapping");

        $vals = preg_split('/[,\s]+/', preg_replace('/#.*/', '', $txt));
        $vals = array_values(array_filter($vals, 'strlen'));

        if (count($vals) !== $this->n) {
            throw new Exception("mapping size mismatch");
        }

        $map = array_map('intval', $vals);
        $chk = $map; sort($chk);
        for ($i=0;$i<$this->n;$i++) if ($chk[$i] !== $i) {
            throw new Exception("mapping must be permutation");
        }
        return $map;
    }

    public function dumpMapping(array $map, string $path): void
    {
        $out = [];
        for ($r=0;$r<$this->rows;$r++) {
            $row=[];
            for ($c=0;$c<$this->cols;$c++) {
                $row[] = $map[$r*$this->cols+$c];
            }
            $out[] = implode(",", $row);
        }
        file_put_contents($path, implode(PHP_EOL,$out).PHP_EOL);
    }

    /* ---------- edge extraction ---------- */

    private static function rgb(int $p): array
    {
        return [($p>>16)&255, ($p>>8)&255, $p&255];
    }

    /**
     * Returns [tile]['L'|'R'|'T'|'B'][k][sample] => [r,g,b]
     * k = distance from the seam (0 = outermost pixel line).
     */
    private function extractEdges(int $kmax): array
    {
        if ($kmax >= $this->tileW || $kmax >= $this->tileH) {
            throw new Exception("band too large. tileW={$this->tileW}, tileH={$this->tileH}, band={$kmax}");
        }
        $step = max(1, $this->step);
        $edges = [];
        for ($i=0;$i<$this->n;$i++) {
            $t = $this->tiles[$i];
            $L=[];$R=[];$T=[];$B=[];
            for ($k=0;$k<$kmax;$k++) {
                $l=[];$r=[];$tt=[];$b=[];
                for ($y=0;$y<$this->tileH;$y+=$step) {
                    $l[] = self::rgb(imagecolorat($t, $k, $y));
                    $r[] = self::rgb(imagecolorat($t, $this->tileW-1-$k, $y));
                }
                for ($x=0;$x<$this->tileW;$x+=$step) {
                    $tt[] = self::rgb(imagecolorat($t, $x, $k));
                    $b[]  = self::rgb(imagecolorat($t, $x, $this->tileH-1-$k));
                }
                $L[]=$l; $R[]=$r; $T[]=$tt; $B[]=$b;
            }
            $edges[$i] = ['L'=>$L,'R'=>$R,'T'=>$T,'B'=>$B];
        }
        return $edges;
    }

    /* ---------- scoring ---------- */

    /** mean / variance (per channel) of the outward gradient s0 - s1 along one side */
    private static function gradStats(array $s0, array $s1): array
    {
        $m = count($s0);
        $mu = [0.0,0.0,0.0]; $var = [0.0,0.0,0.0];
        for ($y=0;$y<$m;$y++) for ($c=0;$c<3;$c++) $mu[$c] += $s0[$y][$c]-$s1[$y][$c];
        for ($c=0;$c<3;$c++) $mu[$c] /= $m;
        for ($y=0;$y<$m;$y++) for ($c=0;$c<3;$c++) {
            $d = $s0[$y][$c]-$s1[$y][$c]-$mu[$c];
            $var[$c] += $d*$d;
        }
        for ($c=0;$c<3;$c++) $var[$c] = $var[$c]/$m + self::MGC_EPS;
        return [$mu,$var];
    }

    /**
     * Symmetric MGC dissimilarity between side A of one tile and side B of another
     * (a0/b0 = outermost pixel line of each side).
     */
    private static function mgcPair(array $a0, array $statA, array $b0, array $statB): float
    {
        [$muA,$varA] = $statA;
        [$muB,$varB] = $statB;
        $m = count($a0); $d = 0.0;
        for ($y=0;$y<$m;$y++) {
            $pa = $a0[$y]; $pb = $b0[$y];
            for ($c=0;$c<3;$c++) {
                $g = $pb[$c]-$pa[$c];               // gradient crossing the seam A -> B
                $e = $g-$muA[$c];  $d += $e*$e/$varA[$c];
                $e = -$g-$muB[$c]; $d += $e*$e/$varB[$c];
            }
        }
        return $d;
    }

    private static function rawPair(array $A, array $B): float
    {
        $d = 0.0; $kmax = count($A);
        for ($k=0;$k<$kmax;$k++) {
            $a = $A[$k]; $b = $B[$k]; $m = count($a);
            for ($y=0;$y<$m;$y++) {
                $d += abs($a[$y][0]-$b[$y][0]) + abs($a[$y][1]-$b[$y][1]) + abs($a[$y][2]-$b[$y][2]);
            }
        }
        return $d;
    }

    /**
     * Turn raw dissimilarities into confidence-scaled costs:
     * each value is divided by the second-best value in its row and in its column,
     * so a unique strong match costs ~0 while ambiguous seams cost ~1.
     */
    private static function normalize(array $D): array
    {
        $n = count($D); $rowRef=[]; $colRef=[];
        for ($i=0;$i<$n;$i++) {
            $v=[]; for ($j=0;$j<$n;$j++) if ($j!==$i) $v[]=$D[$i][$j];
            sort($v); $rowRef[$i] = ($v[1] ?? $v[0] ?? 1.0) + 1e-9;
        }
        for ($j=0;$j<$n;$j++) {
            $v=[]; for ($i=0;$i<$n;$i++) if ($i!==$j) $v[]=$D[$i][$j];
            sort($v); $colRef[$j] = ($v[1] ?? $v[0] ?? 1.0) + 1e-9;
        }
        $C=[];
        for ($i=0;$i<$n;$i++) for ($j=0;$j<$n;$j++) {
            $C[$i][$j] = ($i===$j) ? self::INF
                : 0.5*($D[$i][$j]/$rowRef[$i] + $D[$i][$j]/$colRef[$j]);
        }
        return $C;
    }

    public function buildScoreTables(): void
    {
        $n = $this->n;
        $rawR=[]; $rawD=[];

        if ($this->score === 'raw') {
            $E = $this->extractEdges(max(1,$this->band));
            for ($i=0;$i<$n;$i++) for ($j=0;$j<$n;$j++) {
                if ($i===$j) { $rawR[$i][$j]=self::INF; $rawD[$i][$j]=self::INF; continue; }
                $rawR[$i][$j] = self::rawPair($E[$i]['R'], $E[$j]['L']);
                $rawD[$i][$j] = self::rawPair($E[$i]['B'], $E[$j]['T']);
            }
        } else {
            $E = $this->extractEdges(2);
            $st=[];
            for ($i=0;$i<$n;$i++) foreach (['L','R','T','B'] as $s) {
                $st[$i][$s] = self::gradStats($E[$i][$s][0], $E[$i][$s][1]);
            }
            for ($i=0;$i<$n;$i++) for ($j=0;$j<$n;$j++) {
                if ($i===$j) { $rawR[$i][$j]=self::INF; $rawD[$i][$j]=self::INF; continue; }
                $rawR[$i][$j] = self::mgcPair($E[$i]['R'][0], $st[$i]['R'], $E[$j]['L'][0], $st[$j]['L']);
                $rawD[$i][$j] = self::mgcPair($E[$i]['B'][0], $st[$i]['B'], $E[$j]['T'][0], $st[$j]['T']);
            }
        }

        $this->costR = $this->norm ? self::normalize($rawR) : $rawR;
        $this->costD = $this->norm ? self::normalize($rawD) : $rawD;
    }

    /* ---------- objective ---------- */

    /** neighbours of a position: [[pos, dir], ...]  dir 0=right 1=left 2=down 3=up */
    private function neighbors(int $p): array
    {
        $r = intdiv($p, $this->cols); $c = $p % $this->cols; $nb=[];
        if ($c+1 < $this->cols) $nb[] = [$p+1, 0];
        if ($c > 0)              $nb[] = [$p-1, 1];
        if ($r+1 < $this->rows) $nb[] = [$p+$this->cols, 2];
        if ($r > 0)              $nb[] = [$p-$this->cols, 3];
        return $nb;
    }

    /** cost of tile t having tile u as its neighbour in direction dir */
    private function pairCost(int $t, int $u, int $dir): float
    {
        return match ($dir) {
            0 => $this->costR[$t][$u],
            1 => $this->costR[$u][$t],
            2 => $this->costD[$t][$u],
            default => $this->costD[$u][$t],
        };
    }

    public function totalCost(array $map): float
    {
        $c = 0.0; $cols = $this->cols;
        for ($p=0;$p<$this->n;$p++) {
            if ($p%$cols+1 < $cols)   $c += $this->costR[$map[$p]][$map[$p+1]];
            if ($p+$cols < $this->n)  $c += $this->costD[$map[$p]][$map[$p+$cols]];
        }
        return $c;
    }

    /* ---------- beam search ---------- */

    private function traversalOrders(): array
    {
        $rm=[]; for ($r=0;$r<$this->rows;$r++) for ($c=0;$c<$this->cols;$c++) $rm[]=$r*$this->cols+$c;
        $cm=[]; for ($c=0;$c<$this->cols;$c++) for ($r=0;$r<$this->rows;$r++) $cm[]=$r*$this->cols+$c;
        $all = [$rm, array_reverse($rm), $cm, array_reverse($cm)];
        return array_slice($all, 0, max(1, min(4, $this->orders)));
    }

    /**
     * Beam search filling positions in the given order.
     * States that share the same set of used tiles and the same tiles on the
     * frontier (placed positions touching an unfilled one) are merged.
     */
    private function beamSearch(array $order): array
    {
        $n = $this->n;
        $beam = max(1, $this->beam);
        $cand = max(1, $this->cand);

        // per step: already-placed neighbours of the new position, and the frontier after placing it
        $placedSet=[]; $stepNb=[]; $stepFr=[];
        for ($s=0;$s<$n;$s++) {
            $p = $order[$s]; $nb=[];
            foreach ($this->neighbors($p) as [$q,$dir]) if (isset($placedSet[$q])) $nb[]=[$q,$dir];
            $stepNb[$s] = $nb;
            $placedSet[$p] = true;
            $fr=[];
            foreach (array_keys($placedSet) as $q) {
                foreach ($this->neighbors($q) as [$x]) if (!isset($placedSet[$x])) { $fr[]=$q; break; }
            }
            $stepFr[$s] = $fr;
        }

        // transposed tables so that every neighbour lookup is a plain $row[$t]
        $costR = $this->costR; $costD = $this->costD;
        $costRT = []; $costDT = [];
        for ($i=0;$i<$n;$i++) for ($j=0;$j<$n;$j++) { $costRT[$j][$i] = $costR[$i][$j]; $costDT[$j][$i] = $costD[$i][$j]; }

        $states = [['score'=>0.0,'used'=>0,'placed'=>[]]];
        for ($s=0;$s<$n;$s++) {
            $p = $order[$s]; $nb = $stepNb[$s]; $fr = $stepFr[$s];
            $nbCount = count($nb);
            $keyIdx = [];            // dedup key => index into the parallel arrays below
            $nsc = []; $npar = []; $ntile = [];
            foreach ($states as $si=>$st) {
                $used = $st['used']; $pl = $st['placed']; $base = $st['score'];

                // cost rows for this state's already-placed neighbours
                $rows = [];
                foreach ($nb as [$q,$dir]) {
                    $u = $pl[$q];
                    $rows[] = match ($dir) { 0 => $costRT[$u], 1 => $costR[$u], 2 => $costDT[$u], default => $costD[$u] };
                }

                $cands = [];
                if ($nbCount === 0) {
                    for ($t=0;$t<$n;$t++) if (!(($used>>$t)&1)) $cands[$t] = 0.0;
                } elseif ($nbCount === 1) {
                    $r0 = $rows[0];
                    for ($t=0;$t<$n;$t++) if (!(($used>>$t)&1)) $cands[$t] = $r0[$t];
                } elseif ($nbCount === 2) {
                    $r0 = $rows[0]; $r1 = $rows[1];
                    for ($t=0;$t<$n;$t++) if (!(($used>>$t)&1)) $cands[$t] = $r0[$t]+$r1[$t];
                } else {
                    for ($t=0;$t<$n;$t++) {
                        if (($used>>$t)&1) continue;
                        $c = 0.0; foreach ($rows as $r) $c += $r[$t];
                        $cands[$t] = $c;
                    }
                }
                asort($cands);
                if (count($cands) > $cand) $cands = array_slice($cands, 0, $cand, true);

                $prefix = '';
                foreach ($fr as $q) if ($q !== $p) $prefix .= $pl[$q].',';
                $frHasP = in_array($p, $fr, true);
                foreach ($cands as $t=>$c) {
                    $sc = $base + $c;
                    $key = ($used|(1<<$t)).'|'.$prefix.($frHasP ? $t : '');
                    if (!isset($keyIdx[$key])) {
                        $keyIdx[$key] = count($nsc);
                        $nsc[] = $sc; $npar[] = $si; $ntile[] = $t;
                    } else {
                        $k = $keyIdx[$key];
                        if ($nsc[$k] > $sc) { $nsc[$k] = $sc; $npar[$k] = $si; $ntile[$k] = $t; }
                    }
                }
            }
            array_multisort($nsc, SORT_ASC, SORT_NUMERIC, $npar, $ntile);
            $keep = min($beam, count($nsc));

            $new = [];
            for ($k=0;$k<$keep;$k++) {
                $si = $npar[$k]; $t = $ntile[$k];
                $pl = $states[$si]['placed']; $pl[$p] = $t;
                $new[] = ['score'=>$nsc[$k],'used'=>$states[$si]['used']|(1<<$t),'placed'=>$pl];
            }
            $states = $new;
            if ($this->verbose) {
                fprintf(STDERR, "  step %d/%d  states=%d  best=%.4f\n", $s+1, $n, count($states), $states[0]['score']);
            }
        }
        $best = $states[0]['placed']; ksort($best);
        return array_values($best);
    }

    /* ---------- local refinement ---------- */

    private function shifted(array $map, int $dr, int $dc): array
    {
        $out = [];
        for ($r=0;$r<$this->rows;$r++) for ($c=0;$c<$this->cols;$c++) {
            $nr = ($r+$dr)%$this->rows; $nc = ($c+$dc)%$this->cols;
            $out[$nr*$this->cols+$nc] = $map[$r*$this->cols+$c];
        }
        ksort($out);
        return $out;
    }

    private function rowShifted(array $map, int $r, int $dc): array
    {
        $out = $map;
        for ($c=0;$c<$this->cols;$c++) $out[$r*$this->cols+($c+$dc)%$this->cols] = $map[$r*$this->cols+$c];
        return $out;
    }

    private function colShifted(array $map, int $c, int $dr): array
    {
        $out = $map;
        for ($r=0;$r<$this->rows;$r++) $out[(($r+$dr)%$this->rows)*$this->cols+$c] = $map[$r*$this->cols+$c];
        return $out;
    }

    /** sum of seams touching positions a or b (each seam counted once) */
    private function localCost(array $map, int $a, int $b): float
    {
        $c = 0.0; $seen = [];
        foreach ([$a,$b] as $p) foreach ($this->neighbors($p) as [$q,$dir]) {
            $k = min($p,$q).'-'.max($p,$q);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $c += $this->pairCost($map[$p], $map[$q], $dir);
        }
        return $c;
    }

    public function refine(array $map): array
    {
        $best = $this->totalCost($map);
        $eps = 1e-9;
        do {
            $improved = false;

            // whole-grid toroidal shifts
            for ($dr=0;$dr<$this->rows;$dr++) for ($dc=0;$dc<$this->cols;$dc++) {
                if ($dr===0 && $dc===0) continue;
                $m = $this->shifted($map,$dr,$dc); $c = $this->totalCost($m);
                if ($c < $best-$eps) { $map=$m; $best=$c; $improved=true; }
            }
            // single row / column cyclic shifts
            for ($r=0;$r<$this->rows;$r++) for ($dc=1;$dc<$this->cols;$dc++) {
                $m = $this->rowShifted($map,$r,$dc); $c = $this->totalCost($m);
                if ($c < $best-$eps) { $map=$m; $best=$c; $improved=true; }
            }
            for ($c0=0;$c0<$this->cols;$c0++) for ($dr=1;$dr<$this->rows;$dr++) {
                $m = $this->colShifted($map,$c0,$dr); $c = $this->totalCost($m);
                if ($c < $best-$eps) { $map=$m; $best=$c; $improved=true; }
            }
            // pairwise swaps
            for ($a=0;$a<$this->n;$a++) for ($b=$a+1;$b<$this->n;$b++) {
                $before = $this->localCost($map,$a,$b);
                [$map[$a],$map[$b]] = [$map[$b],$map[$a]];
                $after = $this->localCost($map,$a,$b);
                if ($after < $before-$eps) { $best += $after-$before; $improved=true; }
                else { [$map[$a],$map[$b]] = [$map[$b],$map[$a]]; }
            }
        } while ($improved);
        return $map;
    }

    /* ---------- solver ---------- */

    public function solve(): array
    {
        $bestMap = null; $bestCost = INF;
        foreach ($this->traversalOrders() as $k=>$order) {
            if ($this->verbose) fprintf(STDERR, "order %d:\n", $k+1);
            $map = $this->beamSearch($order);
            $c = $this->totalCost($map);
            if ($this->refine) {
                $map = $this->refine($map);
                $c2 = $this->totalCost($map);
                if ($this->verbose) fprintf(STDERR, "  beam=%.4f  refined=%.4f\n", $c, $c2);
                $c = $c2;
            } elseif ($this->verbose) {
                fprintf(STDERR, "  beam=%.4f\n", $c);
            }
            if ($c < $bestCost) { $bestCost = $c; $bestMap = $map; }
        }
        return $bestMap;
    }

    /* ---------- render ---------- */

    public function render(array $map): GdImage
    {
        $dst=imagecreatetruecolor(
            $this->tileW*$this->cols,
            $this->tileH*$this->rows
        );
        for ($i=0;$i<$this->n;$i++) {
            $t=$map[$i];
            $x=($i%$this->cols)*$this->tileW;
            $y=intdiv($i,$this->cols)*$this->tileH;
            imagecopy($dst,$this->tiles[$t],$x,$y,0,0,$this->tileW,$this->tileH);
        }
        return $dst;
    }
}

/* ---------- CLI ---------- */

function arg($k,$d=null){global $argv;return ($i=array_search($k,$argv))!==false?$argv[$i+1]??$d:$d;}
function flag($k){global $argv;return in_array($k,$argv,true);}

$img=$argv[1]??null;
if(!$img||!file_exists($img))die("image not found\n");

$rows=(int)arg('--rows',4);
$cols=(int)arg('--cols',4);
$wm=(int)arg('--wm',6);
$hm=(int)arg('--hm',4);

$solver=new TileSolver($rows,$cols,$wm,$hm);
$solver->beam=(int)arg('--beam',1200);
$solver->cand=(int)arg('--cand',40);
$solver->band=(int)arg('--band',3);
$solver->step=(int)arg('--step',1);
$solver->score=arg('--score','mgc')==='raw'?'raw':'mgc';
$solver->norm=(bool)(int)arg('--norm',1);
$solver->orders=(int)arg('--orders',4);
$solver->refine=(bool)(int)arg('--refine',1);
$solver->verbose=flag('--verbose');

$t0=microtime(true);
$solver->load($img);
$solver->cutTiles();

if($mapfile=arg('--map')){
    $map=$solver->readMappingFile($mapfile);
    if($solver->verbose){
        $solver->buildScoreTables();
        fprintf(STDERR,"total cost: %.4f
",$solver->totalCost($map));
    }
}else{
    $solver->buildScoreTables();
    $map=$solver->solve();
    if($dump=arg('--dump-map'))$solver->dumpMapping($map,$dump);
    if($solver->verbose)fprintf(STDERR,"total cost: %.4f\n",$solver->totalCost($map));
}

$out=arg('--out','solved.png');
$dst=$solver->render($map);
imagepng($dst,$out);
printf("saved: %s (%.2fs)\n",$out,microtime(true)-$t0);
