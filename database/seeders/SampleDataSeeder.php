<?php

namespace Database\Seeders;

use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Categories
        $sports = Category::create(['name' => 'Sports']);
        $movies = Category::create(['name' => 'Movies']);
        $news = Category::create(['name' => 'News']);

        // 2. Channels
        $channels = [
            ['name' => 'Sky Sports Main Event', 'category_id' => $sports->id, 'url' => 'https://bitdash-a.akamaihd.net/content/sintel/hls/playlist.m3u8'],
            ['name' => 'Sky Sports PL', 'category_id' => $sports->id, 'url' => 'https://bitdash-a.akamaihd.net/content/sintel/hls/playlist.m3u8'],
            ['name' => 'HBO HD', 'category_id' => $movies->id, 'url' => 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4'],
            ['name' => 'HBO Core', 'category_id' => $movies->id, 'url' => 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4'],
            ['name' => 'Netflix Live', 'category_id' => $movies->id, 'url' => 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4'],
            ['name' => 'CNN International', 'category_id' => $news->id, 'url' => 'https://bitdash-a.akamaihd.net/content/sintel/hls/playlist.m3u8'],
            ['name' => 'BBC News', 'category_id' => $news->id, 'url' => 'https://bitdash-a.akamaihd.net/content/sintel/hls/playlist.m3u8'],
            ['name' => 'RTE Sport', 'category_id' => $sports->id, 'url' => 'https://bitdash-a.akamaihd.net/content/sintel/hls/playlist.m3u8'],
            ['name' => 'EuroSport', 'category_id' => $sports->id, 'url' => 'https://bitdash-a.akamaihd.net/content/sintel/hls/playlist.m3u8'],
            ['name' => 'Discovery', 'category_id' => $news->id, 'url' => 'https://bitdash-a.akamaihd.net/content/sintel/hls/playlist.m3u8'],
        ];

        $allCreatedChannels = [];
        foreach ($channels as $c) {
            $allCreatedChannels[] = Channel::create([
                'name' => $c['name'],
                'category_id' => $c['category_id'],
                'stream_url' => $c['url'],
                'logo_url' => 'https://via.placeholder.com/200x120.png?text='.urlencode($c['name']),
                'is_active' => true,
            ]);
        }

        // 3. Bouquets
        $sportsBouquet = Bouquet::create(['name' => 'Sports Pack']);
        $sportsBouquet->channels()->attach(
            array_filter($allCreatedChannels, fn ($c) => $c->category_id == $sports->id)
        );

        $fullBouquet = Bouquet::create(['name' => 'Full Access']);
        $fullBouquet->channels()->attach(array_column($allCreatedChannels, 'id'));

        // 4. Users
        $users = [
            ['username' => 'user1', 'password' => 'pass123', 'bouquets' => [$sportsBouquet->id], 'max' => 1],
            ['username' => 'user2', 'password' => 'pass123', 'bouquets' => [$fullBouquet->id], 'max' => 2],
            ['username' => 'expired_user', 'password' => 'pass123', 'bouquets' => [$fullBouquet->id], 'max' => 1, 'expired' => true],
        ];

        foreach ($users as $u) {
            $user = User::create([
                'username' => $u['username'],
                'password' => Hash::make($u['password']),
                'role' => 'user',
                'status' => 'active',
                'max_connections' => $u['max'],
                'expiration_date' => isset($u['expired']) ? now()->subDay() : now()->addMonths(6),
            ]);
            $user->bouquets()->attach($u['bouquets']);
        }
    }
}
