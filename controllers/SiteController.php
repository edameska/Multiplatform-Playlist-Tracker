<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\filters\Cors;
use app\models\LoginForm;
use app\models\ContactForm;
use app\models\ApiAccount;
use app\components\strategies\SpotifyStrategy;
use app\models\Playlist;
use app\models\PlaylistTrack;
use app\models\Track;

class SiteController extends Controller
{
    public $enableCsrfValidation = false; // for external POSTs

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
            'corsFilter' => [
                'class' => Cors::class,
                'cors' => [
                    'Origin' => ['http://127.0.0.1:8080'], 
                    'Access-Control-Request-Method' => ['POST', 'GET', 'OPTIONS'],
                    'Access-Control-Request-Headers' => ['*'],
                    'Access-Control-Allow-Credentials' => true,
                    'Access-Control-Max-Age' => 3600,
                ],
            ],
        ];
    }

    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    public function actionIndex()
    {
        $playlists = [];

        if (!Yii::$app->user->isGuest) {
            $playlists = Playlist::find()
                ->joinWith('apiAccount')
                ->where(['api_account.user_id' => Yii::$app->user->id])
                ->orderBy(['last_synced_at' => SORT_DESC])
                ->all();
        }

        return $this->render('index', [
            'playlists' => $playlists,
        ]);
    }

    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) return $this->goHome();

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }

        $model->password = '';
        return $this->render('login', ['model' => $model]);
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }

    public function actionContact()
    {
        $model = new ContactForm();
        if ($model->load(Yii::$app->request->post()) && $model->contact(Yii::$app->params['adminEmail'])) {
            Yii::$app->session->setFlash('contactFormSubmitted');
            return $this->refresh();
        }
        return $this->render('contact', ['model' => $model]);
    }

    public function actionAbout()
    {
        return $this->render('about');
    }

    public function actionSignup()
    {
        $model = new \app\models\SignupForm();
        if ($model->load(Yii::$app->request->post()) && $user = $model->signup()) {
            Yii::$app->session->setFlash('success', 'Registration successful. You can now login.');
            return $this->redirect(['site/login']);
        }
        return $this->render('signup', ['model' => $model]);
    }

   // ================= Spotify ================= //

public function actionSpotifyLogin()
{
    $strategy = new SpotifyStrategy();
    return $this->redirect($strategy->getAuthUrl());
}

public function actionSpotifyCallback($code = null)
{
    if (!$code) {
        return $this->redirect(['site/index']);
    }

    if (Yii::$app->user->isGuest) {
        return $this->renderContent('Login first.');
    }

    $userId = Yii::$app->user->id;
    $strategy = new SpotifyStrategy();

    try {
        // 1. Exchange code for token and save ApiAccount
        $tokens = $strategy->exchangeCodeForToken($code);
        if (empty($tokens['access_token'])) {
            throw new \Exception('Token exchange failed');
        }

        // 2. Sync playlists & tracks (all saved in DB)
        $playlists = $strategy->syncPlaylistsAndTracks($tokens['access_token']);

        return $this->renderContent('Spotify synced successfully.');

    } catch (\Throwable $e) {
        Yii::error($e->getMessage(), __METHOD__);
        return $this->renderContent("Spotify error: {$e->getMessage()}");
    }
}
public function actionSyncPlaylist()
{
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

    $playlistId = Yii::$app->request->post('id');
    $playlist = Playlist::findOne($playlistId);

    if (!$playlist || $playlist->platform !== 'spotify') {
        Yii::warning("Playlist not found or not Spotify: id=$playlistId");
        return ['success' => false, 'message' => 'Playlist not found or not Spotify.'];
    }

    $apiAccount = $playlist->apiAccount;
    if (!$apiAccount) {
        Yii::warning("API account missing for playlist: {$playlist->name}");
        return ['success' => false, 'message' => 'API account missing.'];
    }

    $strategy = new \app\components\strategies\SpotifyStrategy();
    $spotifyId = $playlist->platform_id;
    $addedCount = 0;

    try {
        $allItems = [];
        $limit = 100;
        $offset = 0;

        do {
            // Get tracks as JSON string
            $responseJson = $strategy->getPlaylistTracks($spotifyId, $limit, $offset);

            // Decode JSON to array
            $items = json_decode($responseJson, true);
            if (!is_array($items)) {
                Yii::error("Failed to decode playlist tracks JSON for playlist $spotifyId at offset $offset");
                $items = [];
            }

            Yii::info("Fetched playlist tracks (offset $offset, limit $limit): " . json_encode($items, JSON_UNESCAPED_UNICODE));

            // Merge into the full list
            $allItems = array_merge($allItems, $items);

            // Increase offset
            $offset += $limit;

        } while (!empty($items)); // loop until no more tracks


        Yii::info("Total tracks/items fetched for playlist {$playlist->name}: " . count($allItems));

        foreach ($allItems as $index => $item) {
            if (!$item) {
                Yii::warning("Skipping null item at index $index: " . json_encode($item));
                continue;
            }

            // Use full item as track data
            $trackData = $item;

            // Skip non-tracks or local tracks
            if (($trackData['track']['type'] ?? null) !== 'track' || ($trackData['track']['is_local'] ?? false)) {
                Yii::warning("Skipping non-track or local item at index $index: " . json_encode($trackData));
                continue;
            }

            Yii::info("Processing track at index $index: " . ($trackData['track']['id'] ?? 'unknown'));

            // Find or create track
            $track = Track::findOne([
                'platform'    => 'spotify',
                'platform_id' => $trackData['track']['id'] ?? null,
            ]) ?? new Track();

            $track->platform = 'spotify';
            $track->platform_id = $trackData['track']['id'] ?? '';
            $track->title = $trackData['track']['name'] ?? '';
            $track->artist = implode(', ', array_column($trackData['track']['artists'] ?? [], 'name'));
            $track->album = $trackData['track']['album']['name'] ?? '';
            $track->duration_ms = $trackData['track']['duration_ms'] ?? 0;
            $track->preview_url = $trackData['track']['preview_url'] ?? null;
            $track->raw = json_encode($trackData, JSON_UNESCAPED_UNICODE);

            if (!$track->save()) {
                Yii::warning("Failed to save track to DB: {$track->platform_id} / {$track->title}");
                continue;
            }

            Yii::info("Inserted/Updated track: {$track->platform_id} - {$track->title}");

            // Map track to playlist
            $pt = PlaylistTrack::findOne([
                'playlist_id' => $playlist->id,
                'track_id'    => $track->id,
            ]) ?? new PlaylistTrack();

            if ($pt->isNewRecord) {
                $pt->playlist_id = $playlist->id;
                $pt->track_id = $track->id;
                $pt->position = $trackData['track']['track_number'] ?? 0;
                $pt->added_by_api = true;
                if ($pt->save()) {
                    Yii::info("Added track {$track->title} to playlist {$playlist->name} at position {$pt->position}");
                    $addedCount++;
                } else {
                    Yii::warning("Failed to map track {$track->title} to playlist {$playlist->name}");
                }
            } else {
                Yii::info("Track {$track->title} already mapped to playlist {$playlist->name}");
            }
        }

        // Update playlist metadata
        $playlist->track_count = PlaylistTrack::find()->where(['playlist_id' => $playlist->id])->count();
        $playlist->last_synced_at = date('Y-m-d H:i:s');
        $playlist->save();
        Yii::info("Playlist {$playlist->name} synced, total tracks: {$playlist->track_count}");

        return ['success' => true, 'message' => "Synced $addedCount new tracks."];

    } catch (\Throwable $e) {
        Yii::error("Sync playlist error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

}