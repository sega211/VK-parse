<?php

namespace App\Console\Commands;

use App\Models\News;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchSocialNews extends Command
{
    protected $signature = 'news:fetch';
    protected $description = 'Fetch news from social networks';

    // Ошибки VK API
    const VK_ERRORS = [
        5 => 'Invalid token',
        6 => 'Too many requests',
        15 => 'Access denied',
        30 => 'Profile is private',
        100 => 'Invalid parameter',
        113 => 'Invalid user ID',
        200 => 'Access denied'
    ];

    public function handle()
    {
        $this->info('Starting news fetch process...');
        $this->fetchVk();
        $this->info('News fetched successfully');
    }

    private function fetchVk()
    {
        $this->info('Fetching VK posts...');
        
        try {
            $response = Http::retry(3, 1000)->get('https://api.vk.com/method/wall.get', [
                'domain' => env('VK_PUBLIC_DOMAIN', 'mnogodeto4ka_web'),
                'count' => 50,
                'access_token' => env('VK_API_TOKEN'),
                'v' => '5.199',
                'filter' => 'all'
            ]);

            if ($response->failed()) {
                $this->error("VK API request failed: " . $response->status());
                return;
            }

            $data = $response->json();
            
            // Сохраняем полный ответ для отладки
            $this->saveDebugData('vk_response', $data);
            Log::debug('VK API Response', ['response' => $data]);

            // Обработка ошибок API
            if (isset($data['error'])) {
                $this->handleVkError($data['error']);
                return;
            }

            $posts = $data['response']['items'] ?? [];
            $this->info("Found " . count($posts) . " posts in VK");

            foreach ($posts as $index => $post) {
                $this->info("Processing post #" . ($index + 1) . " ID: " . ($post['id'] ?? 'unknown'));
                
                // Задержка для соблюдения лимитов API (кроме первого поста)
                if ($index > 0) {
                    sleep(1);
                }
                
                // Проверяем все посты на наличие ссылок Дзена
                $isZen = $this->isZenRepost($post) || 
                         (!empty($post['copy_history']) && $this->isZenRepost($post['copy_history'][0]));
                
                if ($isZen) {
                    $this->processZenRepost($post);
                } else {
                    $this->processVkPost($post);
                }
            }

        } catch (\Exception $e) {
            Log::error("VK fetch error: " . $e->getMessage());
            $this->error("Error: " . $e->getMessage());
        }
    }

    private function isZenRepost(array $post): bool
    {
        // Проверка вложений
        if (!empty($post['attachments'])) {
            foreach ($post['attachments'] as $attachment) {
                if ($attachment['type'] === 'link' && isset($attachment['link']['url'])) {
                    $url = $attachment['link']['url'];
                    if (preg_match('/(zen\.yandex|dzen\.ru)/i', $url)) {
                        return true;
                    }
                }
            }
        }
        
        // Проверка текста поста
        if (!empty($post['text'])) {
            preg_match_all('#https?://[^\s]+#', $post['text'], $matches);
            foreach ($matches[0] as $url) {
                if (preg_match('/(zen\.yandex|dzen\.ru)/i', $url)) {
                    return true;
                }
            }
        }
        
        // Проверка истории репостов (для репостов)
        if (!empty($post['copy_history'])) {
            foreach ($post['copy_history'] as $repost) {
                if ($this->isZenRepost($repost)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function processZenRepost(array $post)
    {
        Log::debug("Processing Zen content", ['post_id' => $post['id'] ?? 'unknown']);
        $this->info("  Detected Zen content, processing...");
        
        // Определяем основной пост с контентом Дзена
        $sourcePost = $post;
        if (!empty($post['copy_history'])) {
            foreach ($post['copy_history'] as $repost) {
                if ($this->isZenRepost($repost)) {
                    $sourcePost = $repost;
                    break;
                }
            }
        }
        
        // Извлекаем данные из поста
        $text = $this->cleanText($sourcePost['text'] ?? '');
        
        // Если текст пустой, пытаемся получить из вложений
        if (empty($text)) {
            $text = $this->getTextFromLinkAttachment($sourcePost);
        }
        
        // Пропускаем пустые посты
        if (empty($text)) {
            Log::debug("Skipping empty Zen content");
            $this->info("  Skipping empty Zen content");
            return;
        }
        
        // Извлекаем ссылку на оригинал в Дзене
        $zenUrl = $this->getZenUrl($sourcePost);
        
        // Если не нашли URL во вложениях, ищем в тексте
        if (!$zenUrl && !empty($sourcePost['text'])) {
            preg_match_all('#https?://[^\s]+#', $sourcePost['text'], $matches);
            foreach ($matches[0] as $url) {
                if (preg_match('/(zen\.yandex|dzen\.ru)/i', $url)) {
                    $zenUrl = $this->normalizeZenUrl($url);
                    $this->info("  Found Zen URL in text: " . $zenUrl);
                    break;
                }
            }
        }
        
        if (!$zenUrl) {
            Log::warning("Zen URL not found for content", ['post_id' => $sourcePost['id'] ?? 'unknown']);
            $this->warn("  Zen URL not found, using VK link");
            $zenUrl = "https://vk.com/wall{$post['owner_id']}_{$post['id']}";
        } else {
            $this->info("  Using Zen URL: " . $zenUrl);
        }
           // Используем хеш URL для идентификации
        $externalId = 'zen_' . md5($zenUrl);
         $existingNews = News::where('external_id', $externalId)->first();
        
       
         if ($existingNews && $existingNews->is_manual) {
            $this->info("  Skipping manually edited Zen content: {$externalId}");
            return;
        }
        // Формируем данные
        $newsData = [
            'source' => 'zen',
            'title' => $this->generateTitle($text, $sourcePost),
            'content' => $text,
            'excerpt' => $this->truncateText($text, 200),
            'image' => $this->cleanImageUrl($this->extractBestImage($sourcePost)),
            'link' => $zenUrl,
            'date' => date('Y-m-d H:i:s', $post['date']),
             'is_manual' => false
        ];
         Log::debug("Saving Zen content", [
            'external_id' => $externalId,
            'newsData' => $newsData
        ]);
        $this->info("  Saving Zen content with external ID: " . $externalId);
        
        // Сохраняем в БД
        try {
            News::updateOrCreate(
                [
                    'external_id' => $externalId,
                    'source' => 'zen'
                ],
                $newsData
            );
            $this->info("  Saved Zen content: {$newsData['title']}");
        } catch (\Exception $e) {
            Log::error("Failed to save Zen content: " . $e->getMessage());
            $this->error("  DB error: " . $e->getMessage());
        }
    }

    private function getZenUrl(array $post): ?string
    {
        // Поиск во вложениях
        foreach ($post['attachments'] ?? [] as $attachment) {
            if ($attachment['type'] === 'link' && isset($attachment['link']['url'])) {
                $url = $attachment['link']['url'];
                if (preg_match('/(zen\.yandex|dzen\.ru)/i', $url)) {
                    return $this->normalizeZenUrl($url);
                }
            }
        }
        
        // Поиск в тексте
        if (!empty($post['text'])) {
            preg_match_all('#https?://[^\s]+#', $post['text'], $matches);
            foreach ($matches[0] as $url) {
                if (preg_match('/(zen\.yandex|dzen\.ru)/i', $url)) {
                    return $this->normalizeZenUrl($url);
                }
            }
        }
        
        // Поиск в репостах
        if (!empty($post['copy_history'])) {
            foreach ($post['copy_history'] as $repost) {
                if ($url = $this->getZenUrl($repost)) {
                    return $url;
                }
            }
        }
        
        return null;
    }

    private function normalizeZenUrl(string $url): string
    {
        // Удаляем ненужные параметры
        $url = preg_replace('/\?(share_to|utm_[^=]+=[^&]+|from|cl4url|persist_).*/i', '', $url);
        
        // Приводим к каноническому виду
        $url = str_replace('dzen.ru', 'zen.yandex.ru', $url);
        
        // Удаляем завершающие слэши
        $url = rtrim($url, '?&/');
        
        return $url;
    }

    private function getTextFromLinkAttachment(array $post): string
    {
        if (empty($post['attachments'])) return '';

        foreach ($post['attachments'] as $attachment) {
            if ($attachment['type'] === 'link' && isset($attachment['link'])) {
                // Отдаем приоритет заголовку
                if (!empty($attachment['link']['title'])) {
                    return $this->cleanText($attachment['link']['title']);
                }
                
                // Затем описанию
                if (!empty($attachment['link']['description'])) {
                    return $this->cleanText($attachment['link']['description']);
                }
            }
        }
        
        return '';
    }

    private function processVkPost(array $post)
    {
        if ($this->isZenRepost($post)) {
            $this->info("  Skipping VK post because it contains Zen content");
            return;
        }
        
        $this->info("  Processing VK post...");
        
        // Пропускаем рекламные посты
        if (isset($post['marked_as_ads']) && $post['marked_as_ads'] == 1) {
            $this->info("  Skipping ad post");
            return;
        }

        // Получаем основной текст
        $text = $this->cleanText($post['text'] ?? '');

        // Если текст пустой, пытаемся получить из вложений
        if (empty($text)) {
            $text = $this->getTextFromAttachments($post);
        }

        // Пропускаем полностью пустые посты
        if (empty($text) && empty($post['attachments'])) {
            $this->info("  Skipping empty post");
            return;
        }
        
        // Основной текст
        $fullText = $text;
        
        // Пытаемся получить заголовок из ссылки
        $linkTitle = $this->getTitleFromLinkAttachment($post);
        
        // Title: используем заголовок ссылки или генерируем из текста
        $title = !empty($linkTitle) 
            ? $linkTitle 
            : $this->generateTitle($fullText, $post);
        
        // Excerpt: краткое описание (до 200 символов)
        $excerpt = $this->truncateText($fullText, 200);
        
        // Content: полный текст
        $content = $fullText;

        // Формируем данные
        $newsData = [
            'source' => 'vk',
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'image' => $this->cleanImageUrl($this->extractBestImage($post)),
            'link' => "https://vk.com/wall{$post['owner_id']}_{$post['id']}",
            'date' => date('Y-m-d H:i:s', $post['date']),
            'is_manual' => false
        ];

        // Сохраняем в БД с защитой от дубликатов
        $externalId = $post['id'];
          // Проверяем, существует ли уже такая новость
        $existingNews = News::where('external_id', $externalId)
            ->where('source', 'vk')
            ->first();
        
        // Если новость существует и была отредактирована вручную - пропускаем
        if ($existingNews && $existingNews->is_manual) {
            $this->info("  Skipping manually edited VK post: {$externalId}");
            return;
        }
        
        $this->info("  Saving VK post with external ID: " . $externalId);
        
        try {
            News::updateOrCreate(
                [
                    'external_id' => $externalId,
                    'source' => 'vk'
                ],
                $newsData
            );
            $this->info("  Saved VK post: {$newsData['title']}");
        } catch (\Exception $e) {
            Log::error("Failed to save VK post: " . $e->getMessage());
            $this->error("  DB error: " . $e->getMessage());
        }
    }

    private function generateTitle(string $text, array $post): string
    {
        // Пытаемся получить заголовок из вложений (ссылок)
        if (!empty($post['attachments'])) {
            foreach ($post['attachments'] as $attachment) {
                if ($attachment['type'] === 'link' && 
                    !empty($attachment['link']['title'])
                ) {
                    $title = $this->cleanText($attachment['link']['title']);
                    
                    // Проверяем, что заголовок не слишком короткий (не служебный)
                    if (mb_strlen($title) > 15) {
                        return $title;
                    }
                }
            }
        }

        // Оригинальная логика (первое предложение или обрезанный текст)
        $firstSentence = $this->getFirstSentence($text);
        if (mb_strlen($firstSentence) > 20 && mb_strlen($firstSentence) < 90) {
            return $firstSentence;
        }

        // Для видео используем заголовок видео
        if (!empty($post['attachments'])) {
            foreach ($post['attachments'] as $attachment) {
                if ($attachment['type'] === 'video' && !empty($attachment['video']['title'])) {
                    return $attachment['video']['title'];
                }
            }
        }

        return $this->truncateText($text, 100);
    }

    private function getFirstSentence(string $text): string
    {
        // Ищем конец первого предложения
        $endPos = mb_strpos($text, '.');
        if ($endPos !== false && $endPos > 10) {
            return mb_substr($text, 0, $endPos + 1);
        }
        
        // Ищем первую новую строку
        $endPos = mb_strpos($text, "\n");
        if ($endPos !== false && $endPos > 10) {
            return mb_substr($text, 0, $endPos);
        }
        
        return $text;
    }

    private function getTextFromAttachments(array $post): string
    {
        if (empty($post['attachments'])) return '';

        foreach ($post['attachments'] as $attachment) {
            // Для видео используем описание или заголовок
            if ($attachment['type'] === 'video' && isset($attachment['video'])) {
                $video = $attachment['video'];
                if (!empty($video['description'])) {
                    return $this->cleanText($video['description']);
                }
                if (!empty($video['title'])) {
                    return $this->cleanText($video['title']);
                }
            }
            
            // Для ссылок используем описание
            if ($attachment['type'] === 'link' && isset($attachment['link'])) {
                if (!empty($attachment['link']['description'])) {
                    return $this->cleanText($attachment['link']['description']);
                }
                if (!empty($attachment['link']['title'])) {
                    return $this->cleanText($attachment['link']['title']);
                }
            }
        }

        return '';
    }

    private function getTitleFromLinkAttachment(array $post): ?string
    {
        if (empty($post['attachments'])) return null;

        foreach ($post['attachments'] as $attachment) {
            if ($attachment['type'] === 'link' && 
                !empty($attachment['link']['title']) && 
                mb_strlen($attachment['link']['title']) > 15
            ) {
                return $this->cleanText($attachment['link']['title']);
            }
        }
        
        return null;
    }

    private function extractBestImage(array $post): ?string
    {
        if (empty($post['attachments'])) return null;

        foreach ($post['attachments'] as $attachment) {
            // Обработка видео (берем лучшее превью)
            if ($attachment['type'] === 'video' && isset($attachment['video']['image'])) {
                $images = $attachment['video']['image'];
                usort($images, fn($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));
                return $images[0]['url'] ?? null;
            }
            
            // Обработка фото
            if ($attachment['type'] === 'photo' && isset($attachment['photo']['sizes'])) {
                $sizes = $attachment['photo']['sizes'];
                usort($sizes, fn($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));
                return $sizes[0]['url'] ?? null;
            }
            
            // Обработка ссылок (превью ссылки)
            if ($attachment['type'] === 'link' && isset($attachment['link']['photo'])) {
                $sizes = $attachment['link']['photo']['sizes'] ?? [];
                if (!empty($sizes)) {
                    usort($sizes, fn($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));
                    return $sizes[0]['url'] ?? null;
                }
            }
        }
        
        return null;
    }

    private function cleanText(string $text): string
    {
        // Удаляем ссылки вида [id123|Name]
        $text = preg_replace('/\[([^\|\]]+)\|([^\]]+)\]/', '$2', $text);
        
        // Удаляем оставшиеся скобки
        $text = str_replace(['[', ']'], '', $text);
        
        // Удаляем HTML-теги
        $text = strip_tags($text);
        
        // Заменяем множественные пробелы
        $text = preg_replace('/\s+/', ' ', $text);

        // Декодируем HTML-сущности
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return trim($text);
    }

    private function handleVkError(array $error)
    {
        $code = $error['error_code'];
        $message = self::VK_ERRORS[$code] ?? $error['error_msg'];
        
        $errorMsg = "VK API Error [$code]: $message";
        
        Log::error($errorMsg);
        $this->error($errorMsg);
        
        // Особые действия для критичных ошибок
        if ($code === 5) { // Невалидный токен
            // Можно добавить уведомление администратору
            $this->notifyAdmin("Необходимо обновить VK токен");
        }
    }

    private function notifyAdmin(string $message)
    {
        // Реализация отправки уведомления администратору
        // Например: Mail, Telegram, Slack и т.д.
        Log::alert("Admin notification: $message");
    }

    private function cleanImageUrl(?string $url): ?string
    {
        if (!$url) return null;
        
        // Удаляем только специфичные параметры VK, которые не нужны
        $url = preg_replace('/&from=bu&cs=[^&]+/', '', $url);
        $url = preg_replace('/\?as=[^&]+/', '?', $url);
        $url = preg_replace('/\?$/', '', $url); // Убираем оставшиеся '?'
        
        // Для длинных URL оставляем только базовую часть
        if (mb_strlen($url) > 500) {
            $parts = parse_url($url);
            $cleanUrl = ($parts['scheme'] ?? 'https') . '://' 
                      . ($parts['host'] ?? '') 
                      . ($parts['path'] ?? '');
            return $cleanUrl;
        }
        
        return $url;
    }

    private function truncateText($text, $length)
    {
        if (mb_strlen($text) <= $length) return $text;
        
        $truncated = mb_substr($text, 0, $length);
        
        // Обрезаем до последнего пробела
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        
        return $truncated . '...';
    }

    private function saveDebugData(string $prefix, $data)
    {
        try {
            $timestamp = now()->format('Ymd_His');
            $filename = storage_path("logs/{$prefix}_{$timestamp}.json");
            file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Saved debug data to: " . basename($filename));
        } catch (\Exception $e) {
            Log::error("Failed to save debug data: " . $e->getMessage());
        }
    }
}
