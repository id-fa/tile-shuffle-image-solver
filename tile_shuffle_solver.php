<?php
error_reporting(E_ALL);

/**
 * Generic tile shuffle solver (rows x cols), no rotation.
 * PHP 8.2+ safe (no dynamic properties)
 *
 * Usage:
 *  Solve:
 *   php solve.php shuffle.jpg --rows 4 --cols 4 --wm 6 --hm 4 --beam 1200 --cand 40 --dump-map mapping.txt --out solved.png
 *
 *  Rebuild from mapping:
 *   php solve.php shuffle.jpg --rows 4 --cols 4 --wm 6 --hm 4 --map mapping.txt --out solved.png
 */

class TileSolver
{
    /* ---- configuration ---- */
    public int $rows;
    public int $cols;
    public int $wm;
    public int $hm;

    public int $band = 3;
    public int $step = 2;
    public int $beam = 1200;
    public int $cand = 40;

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

    private array $scoreR = [];
    private array $scoreD = [];

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

    /* ---------- scoring ---------- */

    private function diff(int $a, int $b): int
    {
        return abs(($a>>16&255)-($b>>16&255))
             + abs(($a>>8&255)-($b>>8&255))
             + abs(($a&255)-($b&255));
    }

    public function buildScoreTables(): void
    {
        for ($i=0;$i<$this->n;$i++) {
            for ($j=0;$j<$this->n;$j++) {
                if ($i===$j) {
                    $this->scoreR[$i][$j]=1e15;
                    $this->scoreD[$i][$j]=1e15;
                    continue;
                }
                $sR=0; $sD=0;
                for ($y=0;$y<$this->tileH;$y+=$this->step) {
                    for ($k=0;$k<$this->band;$k++) {
                        $sR += $this->diff(
                            imagecolorat($this->tiles[$i], $this->tileW-1-$k, $y),
                            imagecolorat($this->tiles[$j], $k, $y)
                        );
                    }
                }
                for ($x=0;$x<$this->tileW;$x+=$this->step) {
                    for ($k=0;$k<$this->band;$k++) {
                        $sD += $this->diff(
                            imagecolorat($this->tiles[$i], $x, $this->tileH-1-$k),
                            imagecolorat($this->tiles[$j], $x, $k)
                        );
                    }
                }
                $this->scoreR[$i][$j]=$sR;
                $this->scoreD[$i][$j]=$sD;
            }
        }
    }

    /* ---------- solver ---------- */

    public function solve(): array
    {
        $states=[['score'=>0,'used'=>0,'placed'=>[]]];
        for ($pos=0;$pos<$this->n;$pos++) {
            $next=[];
            foreach ($states as $st) {
                for ($t=0;$t<$this->n;$t++) {
                    if (($st['used']>>$t)&1) continue;
                    $cost=0;
                    if ($pos%$this->cols>0) {
                        $left=$st['placed'][$pos-1];
                        $cost+=$this->scoreR[$left][$t];
                    }
                    if ($pos>=$this->cols) {
                        $up=$st['placed'][$pos-$this->cols];
                        $cost+=$this->scoreD[$up][$t];
                    }
                    $p=$st['placed']; $p[]=$t;
                    $next[]=[
                        'score'=>$st['score']+$cost,
                        'used'=>$st['used']|(1<<$t),
                        'placed'=>$p
                    ];
                }
            }
            usort($next, fn($a,$b)=>$a['score']<=>$b['score']);
            $states=array_slice($next,0,$this->beam);
        }
        return $states[0]['placed'];
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

$img=$argv[1]??null;
if(!$img||!file_exists($img))die("image not found\n");

$rows=(int)arg('--rows',4);
$cols=(int)arg('--cols',4);
$wm=(int)arg('--wm',6);
$hm=(int)arg('--hm',4);

$solver=new TileSolver($rows,$cols,$wm,$hm);
$solver->beam=(int)arg('--beam',($rows*$cols<=16?1200:5000));
$solver->cand=(int)arg('--cand',40);
$solver->band=(int)arg('--band',3);
$solver->step=(int)arg('--step',2);

$solver->load($img);
$solver->cutTiles();

if($mapfile=arg('--map')){
    $map=$solver->readMappingFile($mapfile);
}else{
    $solver->buildScoreTables();
    $map=$solver->solve();
    if($dump=arg('--dump-map'))$solver->dumpMapping($map,$dump);
}

$out=arg('--out','solved.png');
$dst=$solver->render($map);
imagepng($dst,$out);
echo "saved: $out\n";
