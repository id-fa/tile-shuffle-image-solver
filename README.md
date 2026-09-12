# Tile Shuffle Image Solver

[日本語](#日本語) | [English](#english)

---

## 日本語

### 概要

**Tile Shuffle Image Solver** は、  
グリッド状（例：4×4、5×5）に分割され、順序だけがシャッフルされた画像を解析し、  
元の並びを自動的に推定・復元する **画像解析ツール** です。

いわゆる「16(15)パズル風」「タイルシャッフル形式」の画像に対して、  
**境界の勾配整合性（MGC）を用いた探索アルゴリズム** により、元画像を再構築します。

|Target|Result|
|---|---|
|![Target](docs/sample1_(OK).png)|![Result](docs/sample1_(OK)_solved.png)|

---

### 特徴

- 任意サイズ対応（4×4 / 5×5 / NxM、最大 60 タイル）
- 完全シャッフル対応（規則的な並び替え前提なし）
- **MGC（Mahalanobis Gradient Compatibility）** による境界スコアリング  
  タイル内の勾配から境界の先を予測し、その外れ具合を分散で正規化して比較します。空や水面のような一様領域や JPEG ノイズに強い方式です
- スコアの **相対化**（2 番目に良い候補との比で確信度に変換）により、曖昧な境界が探索を支配しない
- **ビームサーチ**（同一状態の統合・4 方向の走査順）で現実的な探索時間
- 探索後の **局所探索**（全体・行・列の巡回シフト、2 タイル交換）で残った誤りを自動修正
- 1px 単位のズレや JPEG 圧縮差に強い
- PHP（GD）単体で動作、PHP 8.2+ 対応
- 一度解析した結果を `mapping.txt` として保存・再利用可能

---

### 想定ユースケース

- タイル分割・シャッフルされた画像の復元
- Web 上で配信されるパズル形式画像の解析
- 研究・教育用途での画像再構成アルゴリズム検証
- 画像処理・探索アルゴリズムの実験素材

---

### 動作要件

- PHP 8.2 以上
- GD 拡張が有効であること
- JPEG/PNG 画像入力

---

### 使い方

#### 1. 自動解析して復元（mapping を生成）

```bash
php tile_shuffle_solver.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --dump-map mapping.txt \
  --out solved.png
```

#### 2. 既存 mapping を使って復元（高速）

```bash
php tile_shuffle_solver.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --map mapping.txt \
  --out solved.png
```

#### 主なパラメータ

| パラメータ               | 既定値   | 説明                                                   |
| ------------------- | ----- | ---------------------------------------------------- |
| `--rows` / `--cols` | 4 / 4 | グリッド分割数                                              |
| `--wm` / `--hm`     | 6 / 4 | セル内トリミング量（境界ノイズ除去用）                                  |
| `--beam`            | 1200  | ビームサーチ幅（大きいほど精度↑・時間↑）                                |
| `--cand`            | 40    | 各状態で試す候補タイル数                                         |
| `--score`           | mgc   | 境界スコア。`mgc`（勾配整合性）または `raw`（旧来の画素差分）                 |
| `--norm`            | 1     | スコアの相対化を行う（0 で無効）                                    |
| `--orders`          | 4     | ビームサーチの走査順の数（行順・逆行順・列順・逆列順）。1 にすると約 4 倍速いが頑健性は下がる |
| `--refine`          | 1     | 探索後の局所探索を行う（0 で無効）                                   |
| `--step`            | 1     | 境界のサンプリング間隔（px）                                      |
| `--band`            | 3     | 境界比較幅（px）。`--score raw` のときのみ有効                       |
| `--verbose`         |       | 進捗とコストを stderr に出力。`--map` と併用するとその mapping のコストを表示  |

---

### 制限事項

* タイルの **回転（90°/180°）には非対応**
* タイルの欠損がある場合は不可
* 星空・単色の空など、ほぼ一様な領域が広い画像では、その領域内のタイル同士が入れ替わることがある
* 最大 60 タイル（PHP 版）/ 64 タイル（WebApp 版）

---

### 参考・ベースとなった実装

本ツールは、以下のリポジトリのアイデア・実装を参考にしつつ、
汎用化・探索アルゴリズムの拡張を行っています。

* **fa0311/jump-downloader**
  [https://github.com/fa0311/jump-downloader](https://github.com/fa0311/jump-downloader)

---

### ライセンス

各自の利用目的・対象コンテンツの利用規約を遵守してください。
本リポジトリのコード自体のライセンスは、リポジトリ内の LICENSE を参照してください。

---

### WebApp

[Trt it.](https://id-fa.github.io/tile-shuffle-image-solver/webapp/)

### 関連

[タイル状にシャッフルされた画像を元に戻すツール - ふぁメモ](https://fa.hatenadiary.jp/entry/20260111/1768130865)


---

## English

### Overview

**Tile Shuffle Image Solver** is a generic image analysis tool that reconstructs
images which have been divided into a grid (e.g. 4×4, 5×5) and shuffled in tile order.

It automatically estimates the original layout using **gradient-based boundary
compatibility (MGC)** and reconstructs the image via beam search plus local refinement.

This tool targets so-called *“16(15)-puzzle style”* or *tile-shuffled images*.

---

### Features

* Supports arbitrary grid sizes (4×4 / 5×5 / NxM, up to 60 tiles)
* Handles fully shuffled tiles (no fixed pattern assumption)
* **MGC (Mahalanobis Gradient Compatibility)** seam scoring: predicts the pixels
  beyond a seam from the gradient inside the tile and normalises the error by the
  tile's own gradient variance. Robust in flat regions (sky, water) and to JPEG noise
* **Confidence normalisation**: every dissimilarity is divided by the second-best
  match, so ambiguous seams cannot dominate the search
* **Beam search** with state merging and four traversal orders
* **Local refinement** after the search (toroidal / row / column shifts, pairwise swaps)
* Robust against 1px drift and JPEG compression differences
* Pure PHP implementation (GD only), PHP 8.2+ compatible
* Mapping export/import for reproducible results

---

### Use Cases

* Reconstruction of tile-shuffled images
* Analysis of puzzle-like image distributions
* Research and educational experiments in image processing
* Testing search and optimization algorithms

---

### Requirements

* PHP 8.2 or later
* GD extension enabled
* JPEG/PNG input images

---

### Usage

#### 1. Solve and generate mapping

```bash
php tile_shuffle_solver.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --dump-map mapping.txt \
  --out solved.png
```

#### 2. Rebuild using an existing mapping

```bash
php tile_shuffle_solver.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --map mapping.txt \
  --out solved.png
```

#### Main options

| Option              | Default | Description                                                              |
| ------------------- | ------- | ------------------------------------------------------------------------ |
| `--rows` / `--cols` | 4 / 4   | Grid size                                                                |
| `--wm` / `--hm`     | 6 / 4   | Inner trimming per cell (boundary noise removal)                         |
| `--beam`            | 1200    | Beam width (larger = more accurate, slower)                              |
| `--cand`            | 40      | Candidate tiles expanded per state                                       |
| `--score`           | mgc     | Seam score: `mgc` (gradient compatibility) or `raw` (legacy L1 diff)     |
| `--norm`            | 1       | Confidence normalisation (0 to disable)                                  |
| `--orders`          | 4       | Number of traversal orders (row, reverse row, column, reverse column). 1 is ~4x faster but less robust |
| `--refine`          | 1       | Local refinement after the search (0 to disable)                         |
| `--step`            | 1       | Sampling interval along the seam (px)                                    |
| `--band`            | 3       | Seam width in px, used by `--score raw` only                             |
| `--verbose`         |         | Progress and costs on stderr; with `--map`, prints the cost of that mapping |

---

### Limitations

* Tile rotation is not supported
* Missing tiles are not supported
* Tiles inside large near-uniform regions (starry sky, flat colour) may still be swapped with each other
* Up to 60 tiles (PHP) / 64 tiles (WebApp)

---

### Reference / Fork Origin

This project is inspired by and derived from:

* **fa0311/jump-downloader**
  [https://github.com/fa0311/jump-downloader](https://github.com/fa0311/jump-downloader)

The algorithm and structure have been generalized and extended for broader use.

---

### License

Please ensure compliance with the terms of use of any images you process.
See the LICENSE file in this repository for code licensing details.

---

### WebApp

[Trt it.](https://id-fa.github.io/tile-shuffle-image-solver/webapp/)

---
