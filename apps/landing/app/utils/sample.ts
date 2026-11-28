/** Sample orders for the demos (the same people as the product screens in design/screens). Not real data. */
export interface SampleOrder {
  id: number
  name: { ar: string; en: string }
  city: { ar: string; en: string }
  amount: number
  risk: number
}

export const SAMPLE_ORDERS: SampleOrder[] = [
  { id: 1048, name: { ar: 'منى عادل', en: 'Mona Adel' }, city: { ar: 'المعادي، القاهرة', en: 'Maadi, Cairo' }, amount: 1250, risk: 72 },
  { id: 1045, name: { ar: 'نور طارق', en: 'Nour Tarek' }, city: { ar: '٦ أكتوبر، الجيزة', en: '6th of October, Giza' }, amount: 760, risk: 81 },
  { id: 1051, name: { ar: 'هنا مصطفى', en: 'Hana Mostafa' }, city: { ar: 'الهرم، الجيزة', en: 'Haram, Giza' }, amount: 2140, risk: 64 },
  { id: 1049, name: { ar: 'مريم حسن', en: 'Mariam Hassan' }, city: { ar: 'المنصورة', en: 'Mansoura' }, amount: 1480, risk: 55 },
  { id: 1050, name: { ar: 'يوسف علي', en: 'Youssef Ali' }, city: { ar: 'سموحة، الإسكندرية', en: 'Smouha, Alexandria' }, amount: 640, risk: 41 },
  { id: 1044, name: { ar: 'سلمى فؤاد', en: 'Salma Fouad' }, city: { ar: 'مصر الجديدة، القاهرة', en: 'Heliopolis, Cairo' }, amount: 1120, risk: 30 },
  { id: 1047, name: { ar: 'مصطفى إبراهيم', en: 'Mostafa Ibrahim' }, city: { ar: 'الزمالك، القاهرة', en: 'Zamalek, Cairo' }, amount: 3200, risk: 23 },
  { id: 1052, name: { ar: 'عمر خالد', en: 'Omar Khaled' }, city: { ar: 'مدينة نصر، القاهرة', en: 'Nasr City, Cairo' }, amount: 890, risk: 18 },
]

export type RiskLevel = 'low' | 'medium' | 'high'
/** The same bands as the confirmation board: high 60+, medium 40–59, low 0–39. */
export const riskLevel = (score: number): RiskLevel => (score >= 60 ? 'high' : score >= 40 ? 'medium' : 'low')

/** The confirmation ladder an unconfirmed order climbs. High-risk orders are asked a deposit before the call. */
export type LadderStep = 'whatsapp' | 'reminder' | 'deposit' | 'call' | 'trikd'
export function nextStep(step: LadderStep, risk: number): LadderStep {
  switch (step) {
    case 'whatsapp':
      return 'reminder'
    case 'reminder':
      return riskLevel(risk) === 'high' ? 'deposit' : 'call'
    case 'deposit':
    case 'call':
      return 'trikd'
    default:
      return 'trikd'
  }
}
