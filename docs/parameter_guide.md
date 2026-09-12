## パラメータ調整ガイド / Parameter Tuning Guide

本ツールは探索型アルゴリズムを用いていますが、
現在の実装（MGC スコア＋相対化＋状態統合ビームサーチ＋局所探索）では
**ほとんどの画像が既定値のまま復元できます。**

以下は **実用上の目安** です。

### 主なパラメータ

| パラメータ | 既定値 | 推奨範囲 | 説明 |
|-----------|--------|----------|------|
| `--rows` / `--cols` | 4 / 4 | 2 ～ 7 | グリッド分割数。最大 60 タイル（WebApp は 64） |
| `--beam` | 1200 | 500 ～ 5000 | ビームサーチ幅。7×7 までは 1200 で十分。誤りが残る場合のみ増やす |
| `--cand` | 40 | 20 ～ 60 | 各状態から試す候補タイル数。タイル数以上にしても意味はない |
| `--orders` | 4 | 1 ～ 4 | 走査順の数（行順・逆行順・列順・逆列順）。1 にすると約 4 倍速いが、局所解に落ちる確率が上がる |
| `--refine` | 1 | 0 / 1 | 探索後の局所探索（全体・行・列の巡回シフト、2 タイル交換） |
| `--score` | mgc | mgc / raw | 境界スコア。`raw` は旧来の画素差分（互換用） |
| `--norm` | 1 | 0 / 1 | スコアを 2 番目に良い候補との比に変換する |
| `--step` | 1 | 1 ～ 3 | 境界サンプリング間隔。大きくすると速いが精度は下がる |
| `--band` | 3 | 1 ～ 4 | 境界比較幅（px）。`--score raw` のときのみ使用 |
| `--wm` / `--hm` | 6 / 4 | 0 ～ 10 | セル内トリミング量（境界ノイズ除去） |

### wm / hm について

配信画像のタイル境界に 1〜数 px のズレや圧縮ノイズがある場合、
`wm`/`hm` でセルの外周を切り落としてから比較します。
境界がきれいな画像（自前で分割した PNG など）では `--wm 0 --hm 0` の方が精度が上がります。

### 実測の目安（PHP 8.4、1000px 程度の画像、既定値）

| グリッド | 時間 |
|---------|------|
| 4×4 | 約 1 秒 |
| 5×5 | 約 1.3 秒 |
| 6×6 | 約 2.6 秒 |
| 7×7 | 約 4.6 秒 |

WebApp 版（ブラウザ）は同じアルゴリズムで、4×4 が 0.4 秒程度です。

### うまく復元できないとき

1. `wm`/`hm` を 0 にしてみる（境界がきれいな画像の場合）
2. `--beam` を 3000 ～ 5000 に上げる
3. 星空・単色の空のように一様な領域内でタイルが入れ替わる場合は、
   境界情報だけでは区別できません。WebApp の手動修正（2 タイルクリック）で直してください
4. `--verbose --map mapping.txt` で任意の mapping のコストを表示できるので、
   正解と比較してスコアの問題か探索の問題かを切り分けられます

---

## メモリ使用量について / Memory Usage Notes

状態統合（同じ使用済み集合と同じ境界タイルを持つ状態の統合）により、
以前の実装より保持する状態数が減っています。
既定値では PHP のデフォルト `memory_limit`（128M）で 7×7 まで動作します。

`--beam` を大きくして不足する場合は、CLI 実行時のみ一時的に変更できます：

```bash
php -d memory_limit=1024M tile_shuffle_solver.php input.png ...
```

---

### English

The solver is search based, but with the current implementation
(MGC scoring, confidence normalisation, beam search with state merging, local refinement)
**most images are reconstructed with the default values.**

The following values are **practical guidelines**.

### Key Parameters

| Parameter | Default | Recommended | Description |
|-----------|---------|-------------|-------------|
| `--rows` / `--cols` | 4 / 4 | 2 – 7 | Grid size. Up to 60 tiles (64 in the WebApp) |
| `--beam` | 1200 | 500 – 5000 | Beam width. 1200 is enough up to 7×7; raise it only if mistakes remain |
| `--cand` | 40 | 20 – 60 | Candidate tiles per state. Values above the tile count have no effect |
| `--orders` | 4 | 1 – 4 | Traversal orders (row, reverse row, column, reverse column). 1 is ~4x faster but more prone to local minima |
| `--refine` | 1 | 0 / 1 | Local refinement after the search (toroidal / row / column shifts, pairwise swaps) |
| `--score` | mgc | mgc / raw | Seam score. `raw` is the legacy L1 pixel difference |
| `--norm` | 1 | 0 / 1 | Divide every score by the second-best candidate |
| `--step` | 1 | 1 – 3 | Sampling interval along the seam. Larger is faster but less accurate |
| `--band` | 3 | 1 – 4 | Seam width in px, used by `--score raw` only |
| `--wm` / `--hm` | 6 / 4 | 0 – 10 | Inner trimming for boundary noise removal |

### About wm / hm

If the tile boundaries of the input carry a few px of drift or compression noise,
`wm`/`hm` trim the cell borders before comparison.
For clean inputs (e.g. PNGs you split yourself) `--wm 0 --hm 0` gives better accuracy.

### Measured timings (PHP 8.4, ~1000 px image, defaults)

| Grid | Time |
|------|------|
| 4×4 | ~1 s |
| 5×5 | ~1.3 s |
| 6×6 | ~2.6 s |
| 7×7 | ~4.6 s |

The WebApp uses the same algorithm and solves a 4×4 in about 0.4 s.

### When the result is wrong

1. Try `--wm 0 --hm 0` for images with clean boundaries
2. Raise `--beam` to 3000 – 5000
3. Tiles inside a uniform region (starry sky, flat colour) cannot be told apart from
   boundary information alone; fix them with the WebApp's two-click swap
4. `--verbose --map mapping.txt` prints the cost of any mapping, which lets you
   tell a scoring problem from a search problem

---

## Memory Usage / PHP Configuration

State merging (states with the same used set and the same frontier tiles are combined)
keeps far fewer states than the previous implementation.
With default values the solver runs up to 7×7 within PHP's default `memory_limit` (128M).

If a larger `--beam` runs out of memory, override the limit for one run:

```bash
php -d memory_limit=1024M tile_shuffle_solver.php input.png ...
```

---
