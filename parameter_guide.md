## パラメータ調整ガイド / Parameter Tuning Guide

本ツールは探索型アルゴリズムを用いているため、  
画像サイズ・分割数に応じてパラメータ調整が重要です。

以下は **実用上の目安** です。

### 主なパラメータ

| パラメータ | 推奨範囲 | 説明 |
|-----------|----------|------|
| `--rows` / `--cols` | 3 ～ 5 | グリッド分割数。5×5 以上は計算量が急増 |
| `--beam` | 800 ～ 2000 (4×4)<br>3000 ～ 8000 (5×5) | ビームサーチ幅。大きいほど精度↑・メモリ/時間↑ |
| `--cand` | 30 ～ 100 | 各状態から試す候補タイル数 |
| `--band` | **1 ～ 4** | 境界比較の幅（px）。大きすぎるとエラーや精度低下の原因 |
| `--step` | 1 ～ 3 | 境界比較のサンプリング間隔 |
| `--wm` / `--hm` | 0 ～ 10 | セル内トリミング量（境界ノイズ除去） |

### band に関する注意

`band` は **タイル境界の比較幅（px）** を指定しますが、

- タイルサイズより大きい値
- 画像サイズに対して過剰な値

を指定すると、以下の問題が発生します：

- `imagecolorat()` が範囲外アクセスを起こす
- PHP Warning / Error が発生する
- 境界スコアが過剰に平均化され、精度が下がる

**実用上は `2` または `3` が最も安定** します。

---

## メモリ使用量について / Memory Usage Notes

探索処理では多数の状態を保持するため、  
PHP のデフォルト設定ではメモリ不足になる場合があります。

### 対策（php.ini）

`php.ini` の以下の設定を調整してください：

```ini
memory_limit = 1024M
````

目安：

* 4×4 画像：256M ～ 512M
* 5×5 画像：512M ～ 1024M 以上推奨

CLI 実行時のみ一時的に変更することも可能です：

```bash
php -d memory_limit=1024M solve.php input.png ...
```

---

### English

Because this tool uses a search-based algorithm,
proper parameter tuning is important depending on image size and grid resolution.

The following values are **practical guidelines**.

### Key Parameters

| Parameter           | Recommended Range                     | Description                                        |
| ------------------- | ------------------------------------- | -------------------------------------------------- |
| `--rows` / `--cols` | 3 – 5                                 | Grid size. Complexity increases rapidly beyond 5×5 |
| `--beam`            | 800 – 2000 (4×4)<br>3000 – 8000 (5×5) | Beam search width                                  |
| `--cand`            | 30 – 100                              | Candidate tiles per state                          |
| `--band`            | **1 – 4**                             | Edge comparison width (px)                         |
| `--step`            | 1 – 3                                 | Sampling step for edge comparison                  |
| `--wm` / `--hm`     | 0 – 10                                | Inner trimming for boundary noise removal          |

### Notes on `band`

`band` defines the pixel width used for edge comparison.

Using excessively large values may cause:

* Out-of-bounds access in `imagecolorat()`
* PHP warnings or runtime errors
* Degraded matching accuracy due to over-averaging

**Values of `2` or `3` are recommended for most images.**

---

## Memory Usage / PHP Configuration

This solver keeps many candidate states in memory.
The default PHP memory limit may be insufficient.

### Recommended php.ini setting

```ini
memory_limit = 1024M
```

Typical requirements:

* 4×4 grids: 256M – 512M
* 5×5 grids: 512M – 1024M or higher

You can also override this per execution:

```bash
php -d memory_limit=1024M solve.php input.png ...
```

---
