<?php

declare(strict_types=1);

use Modules\Addresses\Domain\Governorate;

it('finds a governorate however a store or customer wrote it', function (string $input, Governorate $expected): void {
    expect(Governorate::fromName($input))->toBe($expected);
})->with([
    ['القاهرة', Governorate::Cairo], ['القاهره', Governorate::Cairo], ['محافظة القاهرة', Governorate::Cairo], ['Cairo', Governorate::Cairo], ['cairo ', Governorate::Cairo],
    ['الجيزة', Governorate::Giza], ['جيزة', Governorate::Giza], ['Giza Governorate', Governorate::Giza], ['El Giza', Governorate::Giza],
    ['الإسكندرية', Governorate::Alexandria], ['الاسكندريه', Governorate::Alexandria], ['Alex', Governorate::Alexandria], ['Alexandria', Governorate::Alexandria],
    ['الشرقية', Governorate::Sharqia], ['El Sharkia', Governorate::Sharqia], ['sharqiya', Governorate::Sharqia],
    ['المنوفية', Governorate::Monufia], ['Menoufia', Governorate::Monufia],
    ['كفر الشيخ', Governorate::KafrElSheikh], ['Kafr El-Sheikh', Governorate::KafrElSheikh], ['Kafr el Sheikh', Governorate::KafrElSheikh],
    ['بورسعيد', Governorate::PortSaid], ['Port Said', Governorate::PortSaid], ['Port-Said', Governorate::PortSaid],
    ['بني سويف', Governorate::BeniSuef], ['Beni Suef', Governorate::BeniSuef], ['Bani Sweif', Governorate::BeniSuef],
    ['أسيوط', Governorate::Asyut], ['اسيوط', Governorate::Asyut], ['Assiut', Governorate::Asyut],
    ['الأقصر', Governorate::Luxor], ['البحر الأحمر', Governorate::RedSea], ['Red Sea', Governorate::RedSea],
    ['الفيوم', Governorate::Faiyum], ['Fayoum', Governorate::Faiyum], ['مطروح', Governorate::Matrouh], ['Marsa Matrouh', Governorate::Matrouh],
    ['C', Governorate::Cairo], ['EG-GZ', Governorate::Giza], ['alx', Governorate::Alexandria],
]);

it('says null for what is not a governorate', function (?string $input): void {
    expect(Governorate::fromName($input))->toBeNull();
})->with([null, '', '  ', '6 October', 'Nasr City', 'المعادي', 'Riyadh', 'XX', '123']);
