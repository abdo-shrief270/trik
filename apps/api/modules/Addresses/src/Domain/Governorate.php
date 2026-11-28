<?php

declare(strict_types=1);

namespace Modules\Addresses\Domain;

/** Egypt's 27 governorates, keyed by their ISO 3166-2:EG code. */
enum Governorate: string
{
    case Cairo = 'C';
    case Giza = 'GZ';
    case Alexandria = 'ALX';
    case Qalyubia = 'KB';
    case Sharqia = 'SHR';
    case Dakahlia = 'DK';
    case Gharbia = 'GH';
    case Monufia = 'MNF';
    case Beheira = 'BH';
    case KafrElSheikh = 'KFS';
    case Damietta = 'DT';
    case PortSaid = 'PTS';
    case Ismailia = 'IS';
    case Suez = 'SUZ';
    case Faiyum = 'FYM';
    case BeniSuef = 'BNS';
    case Minya = 'MN';
    case Asyut = 'AST';
    case Sohag = 'SHG';
    case Qena = 'KN';
    case Luxor = 'LX';
    case Aswan = 'ASN';
    case RedSea = 'BA';
    case NewValley = 'WAD';
    case Matrouh = 'MT';
    case NorthSinai = 'SIN';
    case SouthSinai = 'JS';

    public function nameAr(): string
    {
        return match ($this) {
            self::Cairo => 'القاهرة',
            self::Giza => 'الجيزة',
            self::Alexandria => 'الإسكندرية',
            self::Qalyubia => 'القليوبية',
            self::Sharqia => 'الشرقية',
            self::Dakahlia => 'الدقهلية',
            self::Gharbia => 'الغربية',
            self::Monufia => 'المنوفية',
            self::Beheira => 'البحيرة',
            self::KafrElSheikh => 'كفر الشيخ',
            self::Damietta => 'دمياط',
            self::PortSaid => 'بورسعيد',
            self::Ismailia => 'الإسماعيلية',
            self::Suez => 'السويس',
            self::Faiyum => 'الفيوم',
            self::BeniSuef => 'بني سويف',
            self::Minya => 'المنيا',
            self::Asyut => 'أسيوط',
            self::Sohag => 'سوهاج',
            self::Qena => 'قنا',
            self::Luxor => 'الأقصر',
            self::Aswan => 'أسوان',
            self::RedSea => 'البحر الأحمر',
            self::NewValley => 'الوادي الجديد',
            self::Matrouh => 'مطروح',
            self::NorthSinai => 'شمال سيناء',
            self::SouthSinai => 'جنوب سيناء',
        };
    }

    public function nameEn(): string
    {
        return match ($this) {
            self::KafrElSheikh => 'Kafr El Sheikh',
            self::PortSaid => 'Port Said',
            self::BeniSuef => 'Beni Suef',
            self::RedSea => 'Red Sea',
            self::NewValley => 'New Valley',
            self::NorthSinai => 'North Sinai',
            self::SouthSinai => 'South Sinai',
            default => $this->name,
        };
    }

    /** @return array{code: string, name_ar: string, name_en: string} */
    public function toArray(): array
    {
        return ['code' => $this->value, 'name_ar' => $this->nameAr(), 'name_en' => $this->nameEn()];
    }

    /**
     * A governorate as stores and customers write it: "القاهرة", "محافظة الجيزة", "الاسكندريه", "Alex",
     * "El Sharkia", "Menoufia", "C", "EG-GZ"… null when it isn't one (a city like "6 October" is not).
     */
    public static function fromName(?string $name): ?self
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $code = strtoupper(trim($name));
        if (str_starts_with($code, 'EG-')) {
            $code = substr($code, 3);
        }
        if (($byCode = self::tryFrom($code)) !== null) {
            return $byCode;
        }

        $key = self::key($name);
        foreach (self::cases() as $case) {
            if (self::key($case->nameAr()) === $key || self::key($case->nameEn()) === $key) {
                return $case;
            }
        }

        return self::ALIASES[$key] ?? null;
    }

    /** Common spellings beyond the official names, already normalised by key(). */
    private const ALIASES = [
        'alex' => self::Alexandria, 'alexandria' => self::Alexandria, 'eskendereya' => self::Alexandria, 'iskandariya' => self::Alexandria,
        'kahira' => self::Cairo, 'qahira' => self::Cairo, 'gizeh' => self::Giza, 'geeza' => self::Giza, 'jiza' => self::Giza,
        'qalyoubia' => self::Qalyubia, 'kalyubia' => self::Qalyubia, 'qaliubiya' => self::Qalyubia, 'qalubia' => self::Qalyubia, 'kalioubia' => self::Qalyubia,
        'sharkia' => self::Sharqia, 'sharkiya' => self::Sharqia, 'sharqiya' => self::Sharqia, 'sharkeya' => self::Sharqia,
        'dakahleya' => self::Dakahlia, 'daqahlia' => self::Dakahlia, 'dakahlya' => self::Dakahlia, 'dakhalia' => self::Dakahlia,
        'gharbiya' => self::Gharbia, 'gharbeya' => self::Gharbia,
        'menoufia' => self::Monufia, 'menofia' => self::Monufia, 'minufiya' => self::Monufia, 'menoufeya' => self::Monufia,
        'behera' => self::Beheira, 'buhayrah' => self::Beheira, 'beheirah' => self::Beheira,
        'kafrelsheikh' => self::KafrElSheikh, 'kafrsheikh' => self::KafrElSheikh,
        'domyat' => self::Damietta, 'dumyat' => self::Damietta,
        'portsaid' => self::PortSaid, 'borsaid' => self::PortSaid,
        'ismailiya' => self::Ismailia, 'ismaileya' => self::Ismailia,
        'suweis' => self::Suez,
        'fayoum' => self::Faiyum, 'fayum' => self::Faiyum,
        'banisuef' => self::BeniSuef, 'banisweif' => self::BeniSuef, 'benisweif' => self::BeniSuef,
        'menia' => self::Minya, 'minia' => self::Minya,
        'assiut' => self::Asyut, 'assuit' => self::Asyut, 'asiut' => self::Asyut,
        'suhag' => self::Sohag, 'souhag' => self::Sohag,
        'kena' => self::Qena, 'qina' => self::Qena,
        'uqsur' => self::Luxor,
        'aswaan' => self::Aswan,
        'bahrahmar' => self::RedSea,
        'wadigedid' => self::NewValley, 'wadijadid' => self::NewValley,
        'matruh' => self::Matrouh, 'marsamatrouh' => self::Matrouh, 'marsamatruh' => self::Matrouh,
        'northsinai' => self::NorthSinai, 'shamalsina' => self::NorthSinai,
        'southsinai' => self::SouthSinai, 'ganoubsina' => self::SouthSinai,
    ];

    /** Lower-case, Arabic letter forms unified, no "محافظة"/"governorate", no leading "ال"/"el"/"al", letters only. */
    private static function key(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s); // diacritics, tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $s = (string) preg_replace('/^محافظه\s*|\bgovernorate\b|\bgov\b|\bmohafza\b/u', '', $s);
        $s = (string) preg_replace('/^(ال|el[\s\-]+|al[\s\-]+)/u', '', trim($s));
        // "kafr el sheikh", "beni-suef": drop separators and inner el/al
        $s = (string) preg_replace('/[\s\-]+(el|al)[\s\-]+/u', ' ', $s);

        return (string) preg_replace('/[^\p{L}]/u', '', $s);
    }
}
