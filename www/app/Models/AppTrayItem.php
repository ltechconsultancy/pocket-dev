<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tile in the App Tray: a link with an icon, title and small subtitle.
 * Added by agents via `pd app-tray:add`, shown from the grid button in the UI.
 */
class AppTrayItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const MAX_LINKS = 8;

    protected $fillable = ['title', 'subtitle', 'url', 'icon', 'links', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
        'links' => 'array',
    ];

    public static function ordered()
    {
        return static::orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * How the UI should render the icon: Font Awesome class, image URL or text (emoji).
     */
    public function getIconTypeAttribute(): string
    {
        return self::iconType($this->icon);
    }

    public static function iconType(?string $icon): string
    {
        $icon = trim((string) $icon);
        if ($icon === '') {
            return 'fa';
        }
        if (preg_match('#^(https?://|/)#', $icon)) {
            return 'image';
        }
        if (preg_match('/^fa[a-z-]*\s|^fa-/', $icon)) {
            return 'fa';
        }

        return 'text';
    }

    /**
     * Normalised icon for the UI: "fa-camera" -> "fa-solid fa-camera", empty -> $default.
     */
    public static function normalizeIcon(?string $icon, string $default = 'fa-solid fa-link'): string
    {
        $icon = trim((string) $icon);
        if (self::iconType($icon) !== 'fa') {
            return $icon;
        }
        if ($icon === '') {
            return $default;
        }
        if (!preg_match('/^fa-(solid|regular|brands|light|thin|duotone)\b|^fa[srbldt]?\s/', $icon)) {
            return 'fa-solid ' . $icon;
        }

        return $icon;
    }

    public function toTrayArray(): array
    {
        $links = array_map(fn(array $link) => [
            'title' => $link['title'] ?? '',
            'url' => $link['url'] ?? '',
            'icon' => self::normalizeIcon($link['icon'] ?? null, 'fa-solid fa-arrow-up-right-from-square'),
            'icon_type' => self::iconType($link['icon'] ?? null),
        ], array_values($this->links ?? []));

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'url' => $this->url,
            'icon' => self::normalizeIcon($this->icon),
            'icon_type' => $this->icon_type,
            'links' => $links,
            'sort_order' => $this->sort_order,
        ];
    }
}
