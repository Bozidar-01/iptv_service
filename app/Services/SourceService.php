<?php

namespace App\Services;

use App\Jobs\SyncSource;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Source;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SourceService
{
    public function getAllSources()
    {
        return Source::with('bouquet')->withCount(['channels as active_channels_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();
    }

    public function createSource(array $data, ?UploadedFile $file = null): Source
    {
        $data['bouquet_id'] = $this->resolveBouquet($data);

        if ($data['type'] === 'file' && $file) {
            $data['file_path'] = $this->storeFile($file);
        }

        $source = Source::create($data);
        $this->queueSync($source);

        return $source;
    }

    public function updateSource(Source $source, array $data, ?UploadedFile $file = null): Source
    {
        $data['bouquet_id'] = $this->resolveBouquet($data);

        if ($data['type'] === 'file' && $file) {
            $this->deleteFile($source);
            $data['file_path'] = $this->storeFile($file);
        }

        if ($data['type'] === 'url') {
            $this->deleteFile($source);
            $data['file_path'] = null;
        }

        $stopsChecking = $source->check_streams && array_key_exists('check_streams', $data) && !$data['check_streams'];

        $source->update($data);

        // Channels the check switched off come back once the admin stops checking this source
        if ($stopsChecking) {
            Channel::where('source_id', $source->id)->where('auto_disabled', true)
                ->update(['is_active' => true, 'auto_disabled' => false]);
        }

        return $source;
    }

    public function deleteSource(Source $source): void
    {
        $this->deleteFile($source);
        $source->delete(); // channels are removed by the FK cascade
    }

    /**
     * @return bool false when a sync is already queued or running
     */
    public function queueSync(Source $source): bool
    {
        if ($source->isBusy()) {
            return false;
        }

        $source->update(['status' => Source::STATUS_QUEUED, 'last_error' => null]);
        SyncSource::dispatch($source);

        return true;
    }

    /**
     * The form sends a bouquet id, "new" (create one named after the source) or nothing.
     */
    private function resolveBouquet(array $data): ?int
    {
        $choice = $data['bouquet_id'] ?? null;

        if ($choice === 'new') {
            return Bouquet::firstOrCreate(['name' => $data['name']])->id;
        }

        return $choice ? (int) $choice : null;
    }

    private function storeFile(UploadedFile $file): string
    {
        return $file->storeAs('sources', Str::uuid().'.m3u');
    }

    private function deleteFile(Source $source): void
    {
        if ($source->file_path) {
            Storage::delete($source->file_path);
        }
    }
}
