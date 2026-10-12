<?php

namespace App\Support\Meta;

final class MetaConnectionKind
{
    public const WHATSAPP_WABA = 'whatsapp_waba';

    public const FACEBOOK_PAGE = 'facebook_page';

    public const INSTAGRAM_USER = 'instagram_user';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::WHATSAPP_WABA,
            self::FACEBOOK_PAGE,
            self::INSTAGRAM_USER,
        ];
    }
}
