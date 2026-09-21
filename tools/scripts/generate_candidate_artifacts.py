"""
Generate candidate model artifacts for admin comparison:
  - prediction_engine_no_e9.json         (51 features: FULL_59 - E9)
  - prediction_engine_no_e9_no_e10.json  (39 features: FULL_59 - E9 - E10)

Training: all 3511 rows (2024/25 + 2025/26), PoissonRegressor(alpha=1.0).
Feature order derived from prediction_engine_v1.json (authoritative).
Output format: identical to production artifact.
"""
import json, warnings
from datetime import date
import numpy as np
import pandas as pd
from sklearn.linear_model import PoissonRegressor
from sklearn.preprocessing import StandardScaler

warnings.filterwarnings('ignore')

REPO = 'C:/xampp/htdocs/robetting'
ART_PATH  = f'{REPO}/tools/models/prediction_engine_v1.json'
CSV_PATH  = f'{REPO}/tools/datasets/dataset_core_v1_2024_2025.csv'
OUT_DIR   = f'{REPO}/tools/models'

E9_FEATURES = [
    'core_opponent_quality_home_last10_average_opponent_elo',
    'core_opponent_quality_home_last10_median_opponent_elo',
    'core_opponent_quality_home_venue_average_opponent_elo',
    'core_opponent_quality_home_venue_median_opponent_elo',
    'core_opponent_quality_away_last10_average_opponent_elo',
    'core_opponent_quality_away_last10_median_opponent_elo',
    'core_opponent_quality_away_venue_average_opponent_elo',
    'core_opponent_quality_away_venue_median_opponent_elo',
]

E10_FEATURES = [
    'core_opponent_adjusted_home_last10_goal_diff_adjusted_avg',
    'core_opponent_adjusted_home_last10_shot_diff_adjusted_avg',
    'core_opponent_adjusted_home_last10_sot_diff_adjusted_avg',
    'core_opponent_adjusted_home_venue_goal_diff_adjusted_avg',
    'core_opponent_adjusted_home_venue_shot_diff_adjusted_avg',
    'core_opponent_adjusted_home_venue_sot_diff_adjusted_avg',
    'core_opponent_adjusted_away_last10_goal_diff_adjusted_avg',
    'core_opponent_adjusted_away_last10_shot_diff_adjusted_avg',
    'core_opponent_adjusted_away_last10_sot_diff_adjusted_avg',
    'core_opponent_adjusted_away_venue_goal_diff_adjusted_avg',
    'core_opponent_adjusted_away_venue_shot_diff_adjusted_avg',
    'core_opponent_adjusted_away_venue_sot_diff_adjusted_avg',
]

def build_artifact(feature_cols, feature_set_version, df, ref_art):
    X = df[feature_cols].values.astype(float)
    medians = np.nanmedian(X, axis=0).tolist()

    X_imp = X.copy()
    for j in range(X_imp.shape[1]):
        m = np.isnan(X_imp[:, j])
        X_imp[m, j] = medians[j]

    sc = StandardScaler()
    X_s = sc.fit_transform(X_imp)

    mh = PoissonRegressor(alpha=1.0, fit_intercept=True, max_iter=1000)
    ma = PoissonRegressor(alpha=1.0, fit_intercept=True, max_iter=1000)
    mh.fit(X_s, df['label_home_goals'].values.astype(float))
    ma.fit(X_s, df['label_away_goals'].values.astype(float))

    return {
        'model_version':       ref_art['model_version'],
        'feature_set_version': feature_set_version,
        'trained_seasons':     ref_art['trained_seasons'],
        'training_matches':    len(df),
        'trained_at':          date.today().isoformat(),
        'algorithm':           ref_art.get('algorithm', 'poisson_glm_dual'),
        'features':            feature_cols,
        'imputer': {
            'strategy': 'median',
            'values':   medians,
        },
        'scaler': {
            'type':  'standard',
            'mean':  sc.mean_.tolist(),
            'scale': sc.scale_.tolist(),
        },
        'home_model': {
            'type':         'poisson_glm',
            'target':       'label_home_goals',
            'alpha':        1.0,
            'fit_intercept': True,
            'intercept':    float(mh.intercept_),
            'coefficients': mh.coef_.tolist(),
        },
        'away_model': {
            'type':         'poisson_glm',
            'target':       'label_away_goals',
            'alpha':        1.0,
            'fit_intercept': True,
            'intercept':    float(ma.intercept_),
            'coefficients': ma.coef_.tolist(),
        },
        'inference': ref_art.get('inference', {'max_goals': 10, 'normalization': 'joint'}),
    }

def main():
    print('Loading artifact feature order...', flush=True)
    with open(ART_PATH, encoding='utf-8') as f:
        ref_art = json.load(f)
    full_59 = ref_art['features']
    assert len(full_59) == 59, f'Expected 59, got {len(full_59)}'

    no_e9_51  = [x for x in full_59 if x not in E9_FEATURES]
    no_e10_39 = [x for x in no_e9_51 if x not in E10_FEATURES]
    assert len(no_e9_51)  == 51, f'Expected 51, got {len(no_e9_51)}'
    assert len(no_e10_39) == 39, f'Expected 39, got {len(no_e10_39)}'

    print(f'Loading dataset...', flush=True)
    df = pd.read_csv(CSV_PATH)
    assert len(df) == 3511, f'Expected 3511, got {len(df)}'
    print(f'  {len(df)} rows loaded', flush=True)

    print('Training NO_E9 (51 features)...', flush=True)
    art_no_e9 = build_artifact(no_e9_51, 'core_v1_no_e9', df, ref_art)
    out_path = f'{OUT_DIR}/prediction_engine_no_e9.json'
    with open(out_path, 'w', encoding='utf-8') as f:
        json.dump(art_no_e9, f, indent=2)
    print(f'  Saved: {out_path}', flush=True)

    print('Training NO_E9+NO_E10 (39 features)...', flush=True)
    art_no_e10 = build_artifact(no_e10_39, 'core_v1_no_e9_no_e10', df, ref_art)
    out_path = f'{OUT_DIR}/prediction_engine_no_e9_no_e10.json'
    with open(out_path, 'w', encoding='utf-8') as f:
        json.dump(art_no_e10, f, indent=2)
    print(f'  Saved: {out_path}', flush=True)

    print('\nDone. Artifacts written:', flush=True)
    print(f'  prediction_engine_no_e9.json         ({len(no_e9_51)} features)', flush=True)
    print(f'  prediction_engine_no_e9_no_e10.json  ({len(no_e10_39)} features)', flush=True)

if __name__ == '__main__':
    main()
