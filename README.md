# Tile Shuffle Image Solver

[日本語](#日本語) | [English](#english)

---

## 日本語

### 概要

**Tile Shuffle Image Solver** は、  
グリッド状（例：4×4、5×5）に分割され、順序だけがシャッフルされた画像を解析し、  
元の並びを自動的に推定・復元する **画像解析ツール** です。

いわゆる「16(15)パズル風」「タイルシャッフル形式」の画像に対して、  
**境界の画素情報を用いた探索アルゴリズム** により、元画像を再構築します。

|Target|Result|
|---|---|
|![Target](docs/sample1_(OK).png)|![Result](docs/sample1_(OK)_solved.png)|

---

### 特徴

- 任意サイズ対応（4×4 / 5×5 / NxM）
- 完全シャッフル対応（規則的な並び替え前提なし）
- タイル境界の画素差分を用いたスコアリング
- **ビームサーチ**による現実的な探索時間
- 1px 単位のズレや JPEG 圧縮差に強い
- PHP（GD）単体で動作
- PHP 8.2+ 対応
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
php solve.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --beam 1200 \
  --cand 40 \
  --dump-map mapping.txt \
  --out solved.png
````

#### 2. 既存 mapping を使って復元（高速）

```bash
php solve.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --map mapping.txt \
  --out solved.png
```

#### 主なパラメータ

| パラメータ               | 説明                    |
| ------------------- | --------------------- |
| `--rows` / `--cols` | グリッド分割数               |
| `--wm` / `--hm`     | セル内トリミング量（境界ノイズ除去用）   |
| `--beam`            | ビームサーチ幅（大きいほど精度↑・時間↑） |
| `--cand`            | 各状態で試す候補タイル数          |
| `--band`            | 境界比較幅（px）             |
| `--step`            | サンプリング間隔              |

---

### 制限事項

* タイルの **回転（90°/180°）には非対応**
* タイルの欠損がある場合は不可
* 均一な色・模様のみの画像では精度が落ちる場合あり
* 5×5 以上ではパラメータ調整が必要

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

---

## English

### Overview

**Tile Shuffle Image Solver** is a generic image analysis tool that reconstructs
images which have been divided into a grid (e.g. 4×4, 5×5) and shuffled in tile order.

It automatically estimates the original layout using **pixel-boundary similarity**
and reconstructs the image via a practical search algorithm.

This tool targets so-called *“16(15)-puzzle style”* or *tile-shuffled images*.

---

### Features

* Supports arbitrary grid sizes (4×4 / 5×5 / NxM)
* Handles fully shuffled tiles (no fixed pattern assumption)
* Boundary-based pixel difference scoring
* **Beam search** for feasible computation time
* Robust against 1px drift and JPEG compression differences
* Pure PHP implementation (GD only)
* PHP 8.2+ compatible
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
php solve.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --beam 1200 \
  --cand 40 \
  --dump-map mapping.txt \
  --out solved.png
```

#### 2. Rebuild using an existing mapping

```bash
php solve.php shuffled.jpg \
  --rows 4 --cols 4 \
  --wm 6 --hm 4 \
  --map mapping.txt \
  --out solved.png
```

---

### Limitations

* Tile rotation is not supported
* Missing tiles are not supported
* Images with highly uniform textures may reduce accuracy
* Larger grids (5×5+) require parameter tuning

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
