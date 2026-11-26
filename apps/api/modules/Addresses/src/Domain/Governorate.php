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
}
