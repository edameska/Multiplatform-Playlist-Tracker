<?php
use yii\helpers\Html;

$this->title = 'My Profile';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="profile-page">
    <h1><?= Html::encode($this->title) ?></h1>

    <div class="row mt-4">
        <!-- Spotify Card -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm p-3">
                <h4>Spotify</h4>
                <?php if ($spotify): ?>
                    <p>Connected as: <strong><?= Html::encode($spotify->platform_user_id ?: 'Unknown') ?></strong></p>
                    <p>Token expires at: <?= Html::encode($spotify->expires_at) ?></p>
                    <span class="badge bg-success">Connected</span>
                <?php else: ?>
                    <?= Html::a('Connect Spotify', ['profile/spotify-connect'], ['class' => 'btn btn-success']) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- YouTube Card -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm p-3">
                <h4>YouTube</h4>
                <?php if ($youtube): ?>
                    <p>Connected</p>
                    <p>Token expires at: <?= Html::encode($youtube->expires_at) ?></p>
                    <span class="badge bg-success">Connected</span>
                <?php else: ?>
                    <?= Html::a('Connect YouTube', ['profile/youtube-connect'], ['class' => 'btn btn-danger']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <hr>

    <div class="mt-4">
        <h3>Account Info</h3>
        <p>User ID: <?= Yii::$app->user->id ?></p>
    </div>
</div>
