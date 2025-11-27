<?php
$this->title = 'Multi-Platform Playlist Tracker';
?>

<div class="site-index">
    <div class="jumbotron text-center bg-transparent mt-5 mb-5">
        <h1 class="display-4">Playlist Tracker</h1>
        <p class="lead">Track playlists across platforms and monitor changes over time.</p>
        <p><a class="btn btn-lg btn-success" href="/playlist/add">+ Add a Playlist</a></p>
    </div>

    <div class="body-content">

        <h3>Your Playlists</h3>
        <div class="row">
            <!-- TODO: Loop your playlists here -->
            <div class="col-lg-4 mb-3">
                <div class="card p-3 shadow-sm">
                    <h4>Example Playlist</h4>
                    <p>Platform: Spotify<br>Songs: 42<br>Last sync: 2 hours ago</p>
                    <a href="#" class="btn btn-outline-primary">View</a>
                    <a href="#" class="btn btn-outline-secondary">Sync Now</a>
                </div>
            </div>
        </div>

        <h3 class="mt-5">Recent Activity</h3>
        <ul>
            <li>Playlist <em>Top Hits</em> added 2 new songs.</li>
            <li>Playlist <em>Chill Vibes</em> synced 3 hours ago.</li>
        </ul>

    </div>
</div>
