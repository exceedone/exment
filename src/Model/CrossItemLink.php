<?php

namespace Exceedone\Exment\Model;

/**
 * @phpstan-consistent-constructor
 */
class CrossItemLink extends ModelBase
{
    protected $table = 'cross_item_links';

    protected $fillable = [
        'from_type',
        'from_id',
        'to_type',
        'to_id',
        'relation_type',
        'external_key',
        'meta_json',
    ];

    protected $casts = ['meta_json' => 'array'];

    // @phpstan-ignore-next-line
    public static function linkRecords($fromType, $fromId, $toType, $toId, $relationType, $meta = [])
    {
        return static::firstOrCreate([
            'from_type' => $fromType,
            'from_id' => $fromId,
            'to_type' => $toType,
            'to_id' => $toId,
            'relation_type' => $relationType,
        ], [
            'meta_json' => $meta,
        ]);
    }

    // @phpstan-ignore-next-line
    public static function unlinkRecords($fromType, $fromId, $toType, $toId, $relationType)
    {
        return static::where('from_type', $fromType)
            ->where('from_id', $fromId)
            ->where('to_type', $toType)
            ->where('to_id', $toId)
            ->where('relation_type', $relationType)
            ->delete();
    }

    // @phpstan-ignore-next-line
    public static function getLinksFrom($fromType, $fromId)
    {
        return static::where('from_type', $fromType)
            ->where('from_id', $fromId)
            ->get();
    }

    // @phpstan-ignore-next-line
    public static function getLinksTo($toType, $toId)
    {
        return static::where('to_type', $toType)
            ->where('to_id', $toId)
            ->get();
    }

    // @phpstan-ignore-next-line
    public static function getRelated($type, $id, $relationType = null)
    {
        $links = static::getLinksFrom($type, $id)
            ->merge(static::getLinksTo($type, $id))
            ->unique('id')
            ->values();

        if (isset($relationType)) {
            return $links->where('relation_type', $relationType)->values();
        }

        return $links;
    }
}
