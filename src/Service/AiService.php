<?php

namespace Pushbrief\PreCommitSummarize\Service;

use OpenAI;
use Symfony\Component\Process\Process;

class AiService
{
    private $apiKey;
    private $model;

    /**
     * Constructor
     * 
     * @param string|null $apiKey OpenAI API key
     * @param string $model OpenAI model to use
     */
    public function __construct(?string $apiKey = null, string $model = 'gpt-3.5-turbo')
    {
        // Load API key from environment variables if not provided
        $this->apiKey = $apiKey ?? $this->getApiKeyFromEnv();
        $this->model = $model;
    }

    /**
     * Get API key from environment variables
     * 
     * @return string|null API key or null if not set
     */
    private function getApiKeyFromEnv(): ?string
    {
        // First try system environment variables (OS level)
        $sysEnv = getenv('OPENAI_API_KEY', true) ?: null;
        // Then try local environment variables (.env file loaded into $_ENV)
        $localEnv = $_ENV['OPENAI_API_KEY'] ?? null;
        // Finally fallback to regular getenv which might check both
        $fallback = getenv('OPENAI_API_KEY') ?: null;

        return $sysEnv ?? $localEnv ?? $fallback;
    }

    /**
     * Analyze file changes using AI
     * 
     * @param array $fileChanges Array of file changes
     * @return array Analysis results
     * @throws \RuntimeException If API key is not set or API call fails
     */
    public function analyzeChanges(array $fileChanges): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key is not set. Set OPENAI_API_KEY environment variable or provide it in the constructor.');
        }

        $results = [];

        // Dosyaları boyutlarına göre sırala (küçükten büyüğe)
        usort($fileChanges, function($a, $b) {
            return strlen($a['patch'] ?? '') <=> strlen($b['patch'] ?? '');
        });
        
        // 10.000 karaktere sığacak kadar dosyayı seç
        $selectedFiles = [];
        $totalLength = 0;
        $promptBaseLength = strlen($this->buildCombinedPrompt([]));
        $maxLength = 10000 - $promptBaseLength;
        
        foreach ($fileChanges as $file) {
            $fileLength = strlen($file['patch'] ?? '');
            if ($totalLength + $fileLength <= $maxLength) {
                $selectedFiles[] = $file;
                $totalLength += $fileLength;
            }
        }
        
        // Seçilen dosyaları tek istekte gönder
        if (!empty($selectedFiles)) {
            $combinedPrompt = $this->buildCombinedPrompt($selectedFiles);
            $response = $this->callOpenAiApi($combinedPrompt);
            
            if (!empty($response) && isset($response['files'])) {
                // Sadece seçilen dosyaların sonuçlarını döndür
                $filteredResults = [];
                foreach ($selectedFiles as $file) {
                    $filename = $file['file'];
                    if (isset($response['files'][$filename])) {
                        $filteredResults[$filename] = $response['files'][$filename];
                    }
                }
                return $filteredResults;
            }
        }
        
        // Hiçbir dosya seçilmediyse veya API yanıtı beklediğimiz formatta değilse boş sonuç döndür

        return $results;
    }

    /**
     * Build prompt for OpenAI API
     * 
     * @param array $file File change information
     * @return string Prompt for OpenAI API
     */
    private function buildPrompt(array $file): string
    {
        $status = $file['status'] ?? 'Unknown';
        $additions = substr_count($file['patch'] ?? '', "\n+") - substr_count($file['patch'] ?? '', "\n+++");
        $deletions = substr_count($file['patch'] ?? '', "\n-") - substr_count($file['patch'] ?? '', "\n---");

        return <<<EOT
                Aşağıdaki dosya değişikliğini analiz et:

                Dosya: {$file['file']}
                Durum: {$status}
                Değişiklikler: +{$additions}, -{$deletions}

                Patch: {$file['patch']}

                1. DEĞİŞİKLİK ÖZETİ:
                - Yapılan değişikliklerin detaylı teknik açıklaması
                - Değişikliğin potansiyel etkileri

                2. KOD KALİTESİ:
                - 10 üzerinden puanlama (1-10)
                - Puanlamanın nedenleri

                3. ÖNERİLER:
                - Kod kalitesi 7'nin altındaysa iyileştirme önerileri
                - Best practice önerileri
                - Güvenlik tavsiyeleri (varsa)

                Yanıtını JSON formatında ver:
                {
                    "summary": "değişiklik özeti",
                    "quality_score": puan,
                    "quality_reasons": ["neden1", "neden2"],
                    "suggestions": ["öneri1", "öneri2"]
                }
                EOT;
    }
    
    /**
     * Build combined prompt for all file changes
     * 
     * @param array $fileChanges Array of file changes
     * @return string Combined prompt for OpenAI API
     */
    private function buildCombinedPrompt(array $fileChanges): string
    {
        // Boş dizi kontrolü
        if (empty($fileChanges)) {
            return "Boş dosya listesi için analiz yapılamaz.";
        }
        
        $filesContent = [];
        $totalAdditions = 0;
        $totalDeletions = 0;
        
        foreach ($fileChanges as $file) {
            $status = $file['status'] ?? 'Unknown';
            $patch = $file['patch'] ?? '';
            $additions = substr_count($patch, "\n+") - substr_count($patch, "\n+++");
            $deletions = substr_count($patch, "\n-") - substr_count($patch, "\n---");
            $totalAdditions += $additions;
            $totalDeletions += $deletions;
            
            $filesContent[] = <<<EOT
Dosya: {$file['file']}
Durum: {$status}
Değişiklikler: +{$additions}, -{$deletions}

Patch: {$patch}
EOT;
        }
        
        $allFilesContent = implode("\n\n-----------------\n\n", $filesContent);
        
        return <<<EOT
Aşağıdaki birden fazla dosyada yapılan değişiklikleri analiz et:

Toplam Değişiklikler: +{$totalAdditions}, -{$totalDeletions}

{$allFilesContent}

Her dosya için aşağıdaki bilgileri içeren bir analiz yap:

1. DEĞİŞİKLİK ÖZETİ:
- Yapılan değişikliklerin detaylı teknik açıklaması
- Değişikliğin potansiyel etkileri

2. KOD KALİTESİ:
- 10 üzerinden puanlama (1-10)
- Puanlamanın nedenleri

3. ÖNERİLER:
- Kod kalitesi 7'nin altındaysa iyileştirme önerileri
- Best practice önerileri
- Güvenlik tavsiyeleri (varsa)

Yanıtını aşağıdaki yapıda JSON formatında ver:
{
  "overall_summary": "tüm değişikliklerin genel özeti",
  "files": {
    "dosya1": {
      "summary": "değişiklik özeti",
      "quality_score": puan,
      "quality_reasons": ["neden1", "neden2"],
      "suggestions": ["öneri1", "öneri2"]
    },
    "dosya2": {
      "summary": "değişiklik özeti",
      "quality_score": puan,
      "quality_reasons": ["neden1", "neden2"],
      "suggestions": ["öneri1", "öneri2"]
    }
  }
}
EOT;
    }

    /**
     * Call OpenAI API
     * 
     * @param string $prompt Prompt for OpenAI API
     * @return array|null Response from OpenAI API or null if API call fails
     */
    private function callOpenAiApi(string $prompt): ?array
    {
        try {
            $data = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Sen bir kod analisti ve teknik yazılımcısın. Kod değişikliklerini analiz edip teknik özetler oluşturuyorsun.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'response_format' => [
                    'type' => 'json_object'
                ],
                'temperature' => 0.7,
                'max_tokens' => 4000 // 100.000 çok yüksek, 4000 daha makul
            ];

            // OpenAI client kullanarak API çağrısı yapma
            $openAi = OpenAI::factory()
                ->withApiKey($this->apiKey)
                ->withBaseUri($_ENV['OPENAI_BASE_URI'] ?? getenv('OPENAI_BASE_URI') ?? "https://api.openai.com")
                ->make();

            $results = $openAi->chat()->create($data);

            if (isset($results->choices[0]->message->content)) {
                $content = $results->choices[0]->message->content;
                // JSON yanıtı ayrıştırma
                try {
                    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    // JSON ayrıştırma başarısız olursa ham içeriği döndür
                    return ['raw_response' => $content];
                }
            }

            return [];
        } catch (\Exception $e) {
            // Hata durumunda boş dizi döndür
            error_log('OpenAI API error: ' . $e->getMessage());
            return [];
        }
    }
}
