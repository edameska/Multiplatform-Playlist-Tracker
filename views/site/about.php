<?php

/** @var yii\web\View $this */

use yii\helpers\Html;

$this->title = 'About';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="site-about">
    <h1><?= Html::encode($this->title) ?></h1>

    <p class="lead">
        Multi-Platform Playlist Tracker helps you keep all your playlists in one place.
    </p>

    <p>
        The application connects to music platforms such as Spotify and YouTube,
        synchronizes your playlists, and presents them through a single, unified interface.
        No manual copying, no platform switching.
    </p>

    <p>
        The project focuses on clean architecture, secure OAuth authentication,
        and scalable data synchronization using adapter-based integrations.
    </p>

    <p class="text-muted">
        Built as an academic project with an emphasis on software design and maintainability.
    </p>
</div>
