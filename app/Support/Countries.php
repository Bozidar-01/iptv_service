<?php

namespace App\Support;

/**
 * Country names and how to recognise a country in channel data (playlist ids, group names, channel names, tags).
 */
class Countries
{
    /** @var array<string, string> ISO code (lower case) => English name */
    public const NAMES = [
        'ad' => 'Andorra', 'ae' => 'United Arab Emirates', 'af' => 'Afghanistan', 'al' => 'Albania', 'am' => 'Armenia', 'ar' => 'Argentina',
        'at' => 'Austria', 'au' => 'Australia', 'az' => 'Azerbaijan', 'ba' => 'Bosnia and Herzegovina', 'bd' => 'Bangladesh', 'be' => 'Belgium',
        'bg' => 'Bulgaria', 'bh' => 'Bahrain', 'bo' => 'Bolivia', 'br' => 'Brazil', 'by' => 'Belarus', 'ca' => 'Canada', 'ch' => 'Switzerland',
        'cl' => 'Chile', 'cn' => 'China', 'co' => 'Colombia', 'cr' => 'Costa Rica', 'cu' => 'Cuba', 'cy' => 'Cyprus', 'cz' => 'Czechia',
        'de' => 'Germany', 'dk' => 'Denmark', 'do' => 'Dominican Republic', 'dz' => 'Algeria', 'ec' => 'Ecuador', 'ee' => 'Estonia', 'eg' => 'Egypt',
        'es' => 'Spain', 'fi' => 'Finland', 'fo' => 'Faroe Islands', 'fr' => 'France', 'gb' => 'United Kingdom', 'ge' => 'Georgia', 'gr' => 'Greece',
        'gt' => 'Guatemala', 'hk' => 'Hong Kong', 'hn' => 'Honduras', 'hr' => 'Croatia', 'hu' => 'Hungary', 'id' => 'Indonesia', 'ie' => 'Ireland',
        'il' => 'Israel', 'in' => 'India', 'iq' => 'Iraq', 'ir' => 'Iran', 'is' => 'Iceland', 'it' => 'Italy', 'jo' => 'Jordan', 'jp' => 'Japan',
        'ke' => 'Kenya', 'kg' => 'Kyrgyzstan', 'kr' => 'South Korea', 'kw' => 'Kuwait', 'kz' => 'Kazakhstan', 'lb' => 'Lebanon', 'lt' => 'Lithuania',
        'lu' => 'Luxembourg', 'lv' => 'Latvia', 'ly' => 'Libya', 'ma' => 'Morocco', 'mc' => 'Monaco', 'md' => 'Moldova', 'me' => 'Montenegro',
        'mk' => 'North Macedonia', 'mt' => 'Malta', 'mx' => 'Mexico', 'my' => 'Malaysia', 'ng' => 'Nigeria', 'ni' => 'Nicaragua', 'nl' => 'Netherlands',
        'no' => 'Norway', 'np' => 'Nepal', 'nz' => 'New Zealand', 'om' => 'Oman', 'pa' => 'Panama', 'pe' => 'Peru', 'ph' => 'Philippines',
        'pk' => 'Pakistan', 'pl' => 'Poland', 'pt' => 'Portugal', 'py' => 'Paraguay', 'qa' => 'Qatar', 'ro' => 'Romania', 'rs' => 'Serbia',
        'ru' => 'Russia', 'sa' => 'Saudi Arabia', 'se' => 'Sweden', 'sg' => 'Singapore', 'si' => 'Slovenia', 'sk' => 'Slovakia', 'sm' => 'San Marino',
        'so' => 'Somalia', 'sv' => 'El Salvador', 'sy' => 'Syria', 'th' => 'Thailand', 'tj' => 'Tajikistan', 'tm' => 'Turkmenistan', 'tn' => 'Tunisia',
        'tr' => 'Turkey', 'tw' => 'Taiwan', 'ua' => 'Ukraine', 'us' => 'United States', 'uy' => 'Uruguay', 'uz' => 'Uzbekistan', 'va' => 'Vatican',
        've' => 'Venezuela', 'vn' => 'Vietnam', 'xk' => 'Kosovo', 'ye' => 'Yemen', 'za' => 'South Africa',
        'ao' => 'Angola', 'aw' => 'Aruba', 'bb' => 'Barbados', 'bf' => 'Burkina Faso', 'bi' => 'Burundi', 'bj' => 'Benin', 'bn' => 'Brunei',
        'bs' => 'Bahamas', 'bt' => 'Bhutan', 'bw' => 'Botswana', 'bz' => 'Belize', 'cd' => 'DR Congo', 'cf' => 'Central African Republic',
        'cg' => 'Congo', 'ci' => 'Ivory Coast', 'cm' => 'Cameroon', 'cv' => 'Cape Verde', 'dj' => 'Djibouti', 'er' => 'Eritrea', 'et' => 'Ethiopia',
        'fj' => 'Fiji', 'ga' => 'Gabon', 'gd' => 'Grenada', 'gh' => 'Ghana', 'gl' => 'Greenland', 'gm' => 'Gambia', 'gn' => 'Guinea',
        'gp' => 'Guadeloupe', 'gq' => 'Equatorial Guinea', 'gu' => 'Guam', 'gy' => 'Guyana', 'ht' => 'Haiti', 'jm' => 'Jamaica', 'kh' => 'Cambodia',
        'ki' => 'Kiribati', 'km' => 'Comoros', 'kn' => 'Saint Kitts and Nevis', 'la' => 'Laos', 'lc' => 'Saint Lucia', 'li' => 'Liechtenstein',
        'lk' => 'Sri Lanka', 'lr' => 'Liberia', 'ls' => 'Lesotho', 'mg' => 'Madagascar', 'ml' => 'Mali', 'mm' => 'Myanmar', 'mn' => 'Mongolia',
        'mo' => 'Macau', 'mq' => 'Martinique', 'mr' => 'Mauritania', 'mu' => 'Mauritius', 'mv' => 'Maldives', 'mw' => 'Malawi', 'mz' => 'Mozambique',
        'na' => 'Namibia', 'ne' => 'Niger', 'nc' => 'New Caledonia', 'pf' => 'French Polynesia', 'pg' => 'Papua New Guinea', 'pr' => 'Puerto Rico',
        'ps' => 'Palestine', 'pw' => 'Palau', 're' => 'Réunion', 'rw' => 'Rwanda', 'sb' => 'Solomon Islands', 'sc' => 'Seychelles', 'sd' => 'Sudan',
        'sl' => 'Sierra Leone', 'sn' => 'Senegal', 'sr' => 'Suriname', 'ss' => 'South Sudan', 'st' => 'São Tomé and Príncipe', 'sz' => 'Eswatini',
        'td' => 'Chad', 'tg' => 'Togo', 'tl' => 'East Timor', 'to' => 'Tonga', 'tt' => 'Trinidad and Tobago', 'tz' => 'Tanzania', 'ug' => 'Uganda',
        'vc' => 'Saint Vincent', 'vu' => 'Vanuatu', 'ws' => 'Samoa', 'zm' => 'Zambia', 'zw' => 'Zimbabwe', 'ax' => 'Åland Islands', 'bm' => 'Bermuda',
        'ky' => 'Cayman Islands', 'je' => 'Jersey', 'gi' => 'Gibraltar', 'im' => 'Isle of Man', 'gg' => 'Guernsey', 'cw' => 'Curaçao', 'sx' => 'Sint Maarten',
        'aq' => 'Antarctica', 'ck' => 'Cook Islands', 'dm' => 'Dominica', 'gf' => 'French Guiana', 'mh' => 'Marshall Islands', 'mp' => 'Northern Mariana Islands',
        'ag' => 'Antigua and Barbuda', 'as' => 'American Samoa', 'vi' => 'US Virgin Islands', 'vg' => 'British Virgin Islands', 'yt' => 'Mayotte',
    ];

    /** Two-letter tags people use in channel names that are not the ISO code. */
    public const TAGS = ['bh' => 'ba', 'sr' => 'rs', 'cg' => 'me', 'ks' => 'xk', 'uk' => 'gb', 'sl' => 'sl'];

    /** Other spellings of a country (lower case) => code. */
    public const ALIASES = [
        'uk' => 'gb', 'great britain' => 'gb', 'england' => 'gb', 'britain' => 'gb', 'usa' => 'us', 'america' => 'us', 'united states of america' => 'us',
        'czech republic' => 'cz', 'macedonia' => 'mk', 'korea' => 'kr', 'republic of korea' => 'kr', 'uae' => 'ae', 'bosnia' => 'ba', 'holland' => 'nl',
        'russian federation' => 'ru', 'turkiye' => 'tr', 'türkiye' => 'tr', 'ex-yu' => null, 'viet nam' => 'vn',
    ];

    /**
     * Words in a group or channel name that point at a country, in order: the first match wins. Mostly for satellite lists whose
     * groups are packages ("GERMAN FTA SD", "Freesat Romania", "ORF Digital") and not countries.
     *
     * @var array<string, string> regex => code
     */
    public const KEYWORDS = [
        '/\bsky sport austria\b|\borf\b|\bösterreich\b|\boesterreich\b|\baustria\b/iu' => 'at',
        '/\bgerman\b|\bdeutsch|\bsky de\b|\bsky bundesliga\b|\bwdr\b|\bndr\b|\bmdr\b|\bswr\b|\bzdf\b|\bard\b|\brtl\b|\bsat\.?1\b|\bprosieben\b|\bpro7\b/iu' => 'de',
        '/\bromania\b|\bfreesat ro\b|\bpro tv\b/iu' => 'ro',
        '/\bbulgaria|\bvivacom\b/iu' => 'bg',
        '/\bhrvatska\b|\bcroatia\b|\bhrt\b|\bhrvatski\b/iu' => 'hr',
        '/\brtsh\b|\balbania\b|\bshqip|\btop channel\b/iu' => 'al',
        '/\bnorway\b|\bnorge\b|\bnrk\b/iu' => 'no',
        '/\bchina\b|\bcctv\b|\bcgtn\b/iu' => 'cn',
        '/\bma.?arska\b|\bmagyar\b|\bhungary\b/iu' => 'hu',
        '/\bsrbija\b|\bserbia\b|\brts ?[0-9]?\b|\bpink\b|\bhappy tv\b|\bprva\b|\bb92\b/iu' => 'rs',
        '/\bmakedonij|\bmacedonia\b|\bmrt ?[0-9]?\b|\bsitel\b|\balsat\b/iu' => 'mk',
        '/\bslovenij|\bslovenia\b|\bpop tv\b|\btv slo\b/iu' => 'si',
        '/\bgreece\b|\bgreek\b|\bert ?[0-9]?\b/iu' => 'gr',
        '/\bturk|\btrt ?[0-9]?\b/iu' => 'tr',
        '/\btv5 ?monde\b|\bfrance ?[0-9]\b|\btf1\b|\bm6\b|\bcanal\+? ?fr\b/iu' => 'fr',
        '/\brai\b|\bmediaset\b|\bitaly\b|\bitalia\b|\bitalija\b|\btivu\W?sat\b|\bcanale ?5\b|\bla7\b/iu' => 'it',
        '/\bspain\b|\bespaña\b|\bespana\b|\bmovistar\b/iu' => 'es',
        '/\bportugal\b|\brtp ?[0-9]?\b/iu' => 'pt',
        '/\bpoland\b|\bpolsat\b|\btvp ?[0-9]?\b/iu' => 'pl',
        '/\bnetherlands\b|\bnpo ?[0-9]?\b|\bnederland\b/iu' => 'nl',
        '/\bamerika\b|\bunited states\b|\busa\b/iu' => 'us',
        '/\bunited kingdom\b|\bbbc\b|\bitv ?[0-9]?\b|\bsky uk\b/iu' => 'gb',
        '/\brussia\b|\brossiya\b|\bpervy\b/iu' => 'ru',
        '/\bukraine\b|\bukraina\b/iu' => 'ua',
        '/\bpolsk|\bpolska\b/iu' => 'pl',
        '/\bnhk\b|\bjapan/iu' => 'jp',
        '/\bkatholisch|\bheisse\b|\bschweiz\b/iu' => 'de',
        '/[α-ωΑ-Ω]{3}/u' => 'gr',
    ];

    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? strtoupper($code);
    }

    /** The code for a country written as text ("Italy", "UK", "VOD Italy", "🇯🇵 / Japan"), or null. */
    public static function fromText(string $text): ?string
    {
        // "<flag> / Japan": the name is after the last slash
        $text = trim(preg_replace('/^.*\//u', '', $text) ?? $text);
        $text = trim(preg_replace('/^vod\s+/iu', '', $text) ?? $text);
        $key = mb_strtolower($text);

        if (array_key_exists($key, self::ALIASES)) {
            return self::ALIASES[$key];
        }
        static $byName = null;
        $byName ??= array_flip(array_map('mb_strtolower', self::NAMES));

        return $byName[$key] ?? null;
    }

    /** The country of a playlist id like "CanalShowsport.ar@SD" (iptv-org style); "uk" is "gb". */
    public static function fromGuideId(?string $id): ?string
    {
        if (!$id || !preg_match('/\.([a-z]{2})(?:@[^@]*)?$/i', $id, $m)) {
            return null;
        }
        $code = strtolower($m[1]);
        $code = $code === 'uk' ? 'gb' : $code;

        return isset(self::NAMES[$code]) ? $code : null;
    }

    /** A country tag at the start of a name: "IT: Rai 1", "|DE| Pro7", "[DE] X", "(US) X", "MK - Kanal 5". */
    public static function fromNameTag(string $name): ?string
    {
        if (!preg_match('/^\s*[\[\(\|]?\s*([A-Z]{2})\s*(?:[\]\)\|]|:|\s-\s)/u', $name, $m)) {
            return null;
        }
        $code = strtolower($m[1]);
        $code = self::TAGS[$code] ?? $code;

        return isset(self::NAMES[$code]) ? $code : null;
    }

    /** The first keyword rule that matches the text. */
    public static function fromKeywords(string $text): ?string
    {
        foreach (self::KEYWORDS as $pattern => $code) {
            if (preg_match($pattern, $text)) {
                return $code;
            }
        }

        return null;
    }
}
