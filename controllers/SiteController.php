<?php
namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\filters\Cors;
use app\models\LoginForm;
use app\models\ContactForm;
use app\models\ApiAccount;
use app\models\Playlist;
use app\models\PlaylistTrack;
use app\models\Track;
use app\components\adapters\SpotifyAdapter;
use app\components\SpotifyService;
use app\components\YoutubeService;
use app\components\adapters\YoutubeAdapter;

class SiteController extends Controller
{
    #public $enableCsrfValidation = false;

    public function behaviors()
    {
        return [
            'access' => [
            'class' => AccessControl::class,
            'only' => ['login', 'logout', 'signup'],
            'rules' => [
                [
                    'actions' => ['login', 'signup'],
                    'allow' => true,
                    'roles' => ['?'], // guests
                ],
                [
                    'actions' => ['logout'],
                    'allow' => true,
                    'roles' => ['@'], // logged in
                ],
            ],
        ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['logout' => ['post']],
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


    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();

        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }

        return $this->render('login', [
            'model' => $model,
        ]);
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
        return $this->render('index', ['playlists' => $playlists]);
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
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


    public function actionContact()
    {
        $model = new ContactForm();

        if ($model->load(Yii::$app->request->post()) && $model->contact('edameska@gmail.com')) {
            Yii::$app->session->setFlash('contactFormSubmitted');
            return $this->refresh();
        }

        return $this->render('contact', ['model' => $model]);
    }
    public function actionAbout()
    {
        return $this->render('about');
    }





    // ================= Spotify ================= //

    public function actionSpotifyLogin()
    {
        $service = new SpotifyService([
            'clientId' => Yii::$app->params['spotifyClientId'],
            'clientSecret' => Yii::$app->params['spotifyClientSecret'],
            'redirectUri' => Yii::$app->params['spotifyRedirectUri'],
        ]);

        return $this->redirect($service->getAuthUrl());
    }

    public function actionSpotifyCallback($code = null)
    {
        if (!$code || Yii::$app->user->isGuest) {
            return $this->redirect(['site/index']);
        }

        $userId = Yii::$app->user->id;
        $service = new SpotifyService([
            'clientId' => Yii::$app->params['spotifyClientId'],
            'clientSecret' => Yii::$app->params['spotifyClientSecret'],
            'redirectUri' => Yii::$app->params['spotifyRedirectUri'],
        ]);

        try {
            $tokens = $service->exchangeCodeForToken($code);
            if (empty($tokens['access_token'])) throw new \Exception('Token exchange failed');

            $apiAccount = ApiAccount::createOrUpdate($userId, 'spotify', [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'scope' => $tokens['scope'] ?? null,
                'token_type' => $tokens['token_type'] ?? null,
                'expires_at' => date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600)),
                'raw' => json_encode($tokens, JSON_THROW_ON_ERROR),
            ]);

            $adapter = new SpotifyAdapter($service);
            $adapter->setTokens($tokens['access_token'], $tokens['refresh_token'] ?? null);

            foreach ($adapter->getPlaylists() as $p) {
                $playlist = Playlist::findOne([
                    'platform_id' => $p['id'],
                    'api_account_id' => $apiAccount->id
                ]) ?? new Playlist();

                $playlist->api_account_id = $apiAccount->id;
                $playlist->platform = 'spotify';
                $playlist->platform_id = $p['id'];
                $playlist->name = $p['name'];
                $playlist->track_count = $p['track_count'];
                $playlist->save();

                $this->syncPlaylistTracks($playlist, $adapter);
            }

            return $this->renderContent('Spotify playlists and tracks synced successfully.');

        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return $this->renderContent("Spotify error: {$e->getMessage()}");
        }
    }

    private function syncPlaylistTracks(Playlist $playlist, SpotifyAdapter $adapter)
    {
        $offset = 0;
        $limit = 100;
        $addedCount = 0;

        Yii::info("Starting sync for playlist {$playlist->name} ({$playlist->platform_id})", __METHOD__);

        do {
            $tracks = $adapter->getPlaylistTracks($playlist->platform_id, $limit, $offset);

            Yii::info("Fetched " . count($tracks) . " tracks from offset $offset", __METHOD__);

            foreach ($tracks as $trackData) {
                if (!isset($trackData['id'])) {
                    Yii::info("Skipping track with no ID: " . json_encode($trackData), __METHOD__);
                    continue;
                }

                $track = Track::findOne([
                    'platform' => 'spotify',
                    'platform_id' => $trackData['id'],
                ]) ?? new Track();

                $track->platform = 'spotify';
                $track->platform_id = $trackData['id'];
                $track->title = $trackData['title'];
                $track->artist = $trackData['artist'];
                $track->album = $trackData['album'];
                $track->duration_ms = $trackData['duration_ms'];
                $track->preview_url = $trackData['preview_url'] ?? null;
                $track->raw = json_encode($trackData, JSON_UNESCAPED_UNICODE);

                if (!$track->save()) {
                    Yii::error("Failed to save track {$track->title}: " . json_encode($track->getErrors()), __METHOD__);
                    continue;
                }

                $pt = PlaylistTrack::findOne([
                    'playlist_id' => $playlist->id,
                    'track_id' => $track->id,
                ]) ?? new PlaylistTrack();

                if ($pt->isNewRecord) {
                    $pt->playlist_id = $playlist->id;
                    $pt->track_id = $track->id;
                    $pt->added_by_api = true;
                    if (!$pt->save()) {
                        Yii::error("Failed to save PlaylistTrack for track {$track->title}: " . json_encode($pt->getErrors()), __METHOD__);
                        continue;
                    }

                    $addedCount++;
                    Yii::info("Added track: {$track->title} by {$track->artist}", __METHOD__); // <-- track-level log
                }
            }

            $offset += $limit;
        } while (!empty($tracks));

        $playlist->track_count = PlaylistTrack::find()->where(['playlist_id' => $playlist->id])->count();
        $playlist->last_synced_at = date('Y-m-d H:i:s');

        if (!$playlist->save()) {
            Yii::error("Failed to update playlist {$playlist->name}: " . json_encode($playlist->getErrors()), __METHOD__);
        } else {
            Yii::info("Playlist {$playlist->name} synced with $addedCount new tracks", __METHOD__);
        }

        return $addedCount;
    }



    public function actionSyncPlaylist()
{
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

    $playlistId = Yii::$app->request->post('id');
    $playlist = Playlist::findOne($playlistId);
    if (!$playlist) {
        return ['success' => false, 'message' => 'Playlist not found.'];
    }

    $apiAccount = $playlist->apiAccount;
    if (!$apiAccount) return ['success' => false, 'message' => 'API account missing.'];

    try {
        $addedCount = 0;

        if ($playlist->platform === 'spotify') {
            $service = new SpotifyService([
                'clientId' => Yii::$app->params['spotifyClientId'],
                'clientSecret' => Yii::$app->params['spotifyClientSecret'],
                'redirectUri' => Yii::$app->params['spotifyRedirectUri'],
            ]);
            $adapter = new \app\components\adapters\SpotifyAdapter($service);
            $adapter->setTokens($apiAccount->access_token, $apiAccount->refresh_token ?? null);
            $addedCount = $this->syncPlaylistTracks($playlist, $adapter);

        } elseif ($playlist->platform === 'youtube') {
            $service = Yii::$app->youtubeService;
            $service->setAccessToken($apiAccount->access_token, $apiAccount->refresh_token ?? null);
            $adapter = new \app\components\adapters\YoutubeAdapter($service);
            Yii::info("Starting sync for playlist {$playlist->name} ({$playlist->platform_id})", __METHOD__);
            $addedCount = $this->syncYoutubePlaylistTracks($playlist, $adapter);
        } else {
            return ['success' => false, 'message' => 'Unknown platform.'];
        }

        return ['success' => true, 'message' => "Synced $addedCount tracks."];
    } catch (\Throwable $e) {
        Yii::error($e->getMessage(), __METHOD__);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

    // ================= Youtube ================= //

    public function actionYoutubeLogin()
    {
        $service = new YoutubeService([
            'clientId' => Yii::$app->params['youtubeClientId'],
            'clientSecret' => Yii::$app->params['youtubeClientSecret'],
            'redirectUri' => Yii::$app->params['youtubeRedirectUri'],
        ]);

        // redirect user to Google auth page
        return $this->redirect($service->getAuthUrl());
    }

    public function actionYoutubeCallback($code = null)
    {
        if (!$code || Yii::$app->user->isGuest) {
            return $this->redirect(['site/index']);
        }

        $userId = Yii::$app->user->id;
        $service = Yii::$app->youtubeService;

        try {
            // exchange code for access + refresh tokens
            $tokens = $service->exchangeCodeForToken($code);
            if (empty($tokens['access_token'])) {
                throw new \Exception('YouTube token exchange failed');
            }

            // find existing account or create new
            $account = ApiAccount::find()
                ->where(['user_id' => $userId, 'platform' => 'youtube'])
                ->one();

            if (!$account) {
                $account = new ApiAccount();
                $account->user_id = $userId;
                $account->platform = 'youtube';
            }

            // set token in service
            $service->setAccessToken($tokens['access_token'], $tokens['refresh_token'] ?? null);


            $channel = $service->getMyChannel();
            $channelId = $channel['id'] ?? null;
            if (!$channelId) throw new \Exception('Failed to get YouTube channel ID');

            // store token + channel ID
            $account->platform_user_id = $channelId;
            $account->access_token = $tokens['access_token'];
            $account->refresh_token = $tokens['refresh_token'] ?? $account->refresh_token;
            $account->token_type = $tokens['token_type'] ?? null;
            $account->expires_at = isset($tokens['expires_in'])
                ? date('Y-m-d H:i:s', time() + (int)$tokens['expires_in'])
                : null;
            $account->scope = $tokens['scope'] ?? null;
            $account->raw = json_encode($tokens, JSON_THROW_ON_ERROR);
            $account->save();

            // ==== Fetch and store playlists immediately ====
            $adapter = new YoutubeAdapter($service);

            // fetch playlists
            $playlists = $adapter->getPlaylists();

            foreach ($playlists as $p) {
                if (empty($p['id'])) continue;

                $playlist = Playlist::findOne([
                    'platform_id' => $p['id'],
                    'api_account_id' => $account->id
                ]) ?? new Playlist();

                $playlist->api_account_id = $account->id;
                $playlist->platform = 'youtube';
                $playlist->platform_id = $p['id'];
                $playlist->name = $p['title'] ?: 'Untitled';
                $playlist->track_count = $p['itemCount'] ?? 0;
                $playlist->save();

                $this->syncYoutubePlaylistTracks($playlist, $adapter);

            }

            return $this->redirect(['profile/index']);

        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return $this->renderContent("YouTube error: {$e->getMessage()}");
        }
    }



private function syncYoutubePlaylistTracks(Playlist $playlist, YoutubeAdapter $adapter)
{
    $addedCount = 0;
    Yii::info("Starting sync for playlist '{$playlist->name}' ({$playlist->platform_id})", __METHOD__);

    $tracks = $adapter->getPlaylistTracks($playlist->platform_id);
    Yii::info("Adapter returned " . count($tracks) . " tracks", __METHOD__);

    foreach ($tracks as $i => $trackData) {
        Yii::info("Track $i raw data: " . json_encode($trackData), __METHOD__);

        $trackId = $trackData['id'] ?? null;
        if (!$trackId) {
            Yii::warning("Skipping track with missing ID", __METHOD__);
            continue;
        }

        $track = Track::findOne(['platform' => 'youtube', 'platform_id' => $trackId]) ?? new Track();
        $track->platform = 'youtube';
        $track->platform_id = $trackId;
        $track->title = $trackData['title'] ?? 'Untitled';
        $track->artist = $trackData['artist'] ?? 'Unknown';
        $track->duration_ms = $trackData['duration_ms'] ?? null;
        $track->preview_url = $trackData['preview_url'] ?? null;
        $track->raw = json_encode($trackData, JSON_UNESCAPED_UNICODE);

        if (!$track->save()) {
            Yii::error("Failed to save track '{$track->title}': " . json_encode($track->getErrors()), __METHOD__);
            continue;
        }

        $pt = PlaylistTrack::findOne(['playlist_id' => $playlist->id, 'track_id' => $track->id]) ?? new PlaylistTrack();
        if ($pt->isNewRecord) {
            $pt->playlist_id = $playlist->id;
            $pt->track_id = $track->id;
            $pt->added_by_api = true;
            if (!$pt->save()) {
                Yii::error("Failed to save PlaylistTrack for '{$track->title}': " . json_encode($pt->getErrors()), __METHOD__);
                continue;
            }
            $addedCount++;
        }
    }

    $playlist->track_count = PlaylistTrack::find()->where(['playlist_id' => $playlist->id])->count();
    $playlist->last_synced_at = date('Y-m-d H:i:s');

    if (!$playlist->save()) {
        Yii::error("Failed to update playlist '{$playlist->name}': " . json_encode($playlist->getErrors()), __METHOD__);
    } else {
        Yii::info("Finished syncing playlist '{$playlist->name}' with $addedCount new tracks", __METHOD__);
    }

    return $addedCount;
}

}
