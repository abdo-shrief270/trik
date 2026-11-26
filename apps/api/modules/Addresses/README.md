# Addresses

Arabic address capture, normalisation, confidence score, local address data.

**Phase:** Launch  ·  **Table prefix:** `addresses_`

Today: Egypt's 27 governorates (`Domain\Governorate`, ISO 3166-2:EG codes, Arabic and English names,
`GET /v1/addresses/governorates`), and the structured `Domain\Address` used on orders (#344): raw text
plus governorate, area, street, building, floor, apartment and landmark, with one Arabic line for people.

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `Domain\Address`, `Domain\Governorate` | structured address and governorates |
| `AddressRules::for($prefix)` | request validation for an address |

## Events

| Emits | Listens to |
|---|---|
| _to be defined_ | |
