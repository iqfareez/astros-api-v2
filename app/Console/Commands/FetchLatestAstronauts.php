<?php

namespace App\Console\Commands;

use App\Models\Astronaut;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FetchLatestAstronauts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-latest-astronauts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will fetch the latest astronauts from the API and store them in the database.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting astronaut data fetch...');

        // Fetch astronauts data from API
        $response = Http::get('http://api.open-notify.org/astros.json');

        if ($response->failed()) {
            $this->error('Failed to fetch astronaut data. Status code: ' . $response->status());
            return 1;
        }

        $jsonResponse = $response->json();

        if ($jsonResponse['message'] !== 'success') {
            $this->error('API response indicates failure');
            return 1;
        }

        $this->info('Got data successfully!');
        $this->info('Found ' . $jsonResponse['number'] . ' astronauts');

        $googleApiKey = env('GOOGLE_API_KEY');
        $customSearchEngineId = env('CUSTOM_SEARCH_ENGINE');

        // Get current astronauts from API
        $currentAstronauts = collect($jsonResponse['people']);
        $currentNames = $currentAstronauts->pluck('name')->toArray();

        // Remove astronauts from database who are no longer in space
        $removedCount = Astronaut::whereNotIn('name', $currentNames)->count();
        if ($removedCount > 0) {
            Astronaut::whereNotIn('name', $currentNames)->delete();
            $this->info("Removed {$removedCount} astronauts who are no longer in space");
        }

        foreach ($currentAstronauts as $person) {
            $name = $person['name'];
            $craft = $person['craft'];

            $this->info("Processing: {$name}");

            // Check if astronaut already exists
            $existingAstronaut = Astronaut::where('name', $name)
                ->where('craft', $craft)
                ->first();

            $needsImage = false;
            $imageUrl = null;

            if ($existingAstronaut) {
                // Astronaut exists, check if they have an image
                if (empty($existingAstronaut->imageUrl)) {
                    $this->info("Astronaut exists but has no image, fetching...");
                    $needsImage = true;
                } else {
                    $this->info("Astronaut already exists with image, skipping...");
                    continue;
                }
            } else {
                // New astronaut, needs image
                $this->info("New astronaut found, fetching image...");
                $needsImage = true;
            }

            // Fetch image if needed
            if ($needsImage) {
                $imageUrl = $this->fetchAstronautImage($name, $craft, $googleApiKey, $customSearchEngineId);
            }

            if ($existingAstronaut) {
                // Update existing astronaut with image
                $existingAstronaut->update([
                    'imageUrl' => $imageUrl,
                ]);
                $this->info("Updated {$name} with image");
            } else {
                // Create new astronaut
                Astronaut::create([
                    'name' => $name,
                    'craft' => $craft,
                    'imageUrl' => $imageUrl,
                ]);
                $this->info("Added new astronaut {$name} to database");
            }

            // Add delay to avoid rate limiting
            sleep(1);
        }

        $this->info('Command completed successfully!');
        return 0;
    }

    /**
     * Fetch astronaut image from Google Custom Search
     */
    private function fetchAstronautImage($name, $craft, $googleApiKey, $customSearchEngineId)
    {
        $searchQuery = $name . ' Astronaut';
        $imageUrl = null;

        try {
            $searchResponse = Http::get('https://www.googleapis.com/customsearch/v1', [
                'key' => $googleApiKey,
                'cx' => $customSearchEngineId,
                'q' => $searchQuery,
                'imgType' => 'face',
                'searchType' => 'image',
                'num' => 1
            ]);

            if ($searchResponse->successful()) {
                $searchData = $searchResponse->json();

                if (!empty($searchData['items'])) {
                    $imageInfo = $searchData['items'][0];
                    $originalImageUrl = $imageInfo['link'];

                    // Download and store the image
                    $imageResponse = Http::get($originalImageUrl);

                    if ($imageResponse->successful()) {
                        // Get file extension from content type or URL
                        $contentType = $imageResponse->header('Content-Type');
                        $extension = $this->getExtensionFromContentType($contentType) ?: 'jpg';

                        // Create filename: <name>-<craft>.<format>
                        $filename = Str::slug($name) . '-' . Str::slug($craft) . '.' . $extension;

                        // Store image in storage/app/public/astronauts/
                        $path = 'astronauts/' . $filename;
                        Storage::put($path, $imageResponse->body());
                        $imageUrl = Storage::url($path);

                        $this->info("Image saved: {$filename}");
                    } else {
                        $this->warn("Failed to download image for {$name}");
                    }
                } else {
                    $this->warn("No image found for {$name}");
                }
            } else {
                $this->warn("Google search failed for {$name}");
            }
        } catch (\Exception $e) {
            $this->error("Error searching for image for {$name}: " . $e->getMessage());
        }

        return $imageUrl;
    }

    /**
     * Get file extension from content type
     */
    private function getExtensionFromContentType($contentType)
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $extensions[$contentType] ?? null;
    }
}
