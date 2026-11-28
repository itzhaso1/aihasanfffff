<?php

namespace App\Support\Chat;

/**
 * Extensible Gulf semantic groups. Forms are matched after ArabicSearchNormalizer.
 * Intent groups never become product search tokens.
 */
final class GulfArabicLexicon
{
    /**
     * @return list<array{id:string,kind:string,forms:list<string>,dialects:list<string>}>
     */
    public function groups(): array
    {
        return [
            $this->intent('request', ['ابي', 'ابغى', 'ابغا', 'ابيك', 'ودي', 'بدي', 'عايز', 'عاوز', 'محتاج', 'ابيك تشوف', 'ابي اشوف', 'عطني', 'ورني', 'ابغى اشوف', 'ابغاه', 'ابيه', 'باخذه', 'باخذها', 'احجزه', 'احجزلي', 'اريده', 'بشتريه', 'اطلب', 'بطلب'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('availability', ['عندكم', 'عندك', 'متوفر', 'موجود', 'فيه', 'في', 'عدكم', 'متاح', 'خلص', 'نفد'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('price', [
                'بكم', 'كم', 'بقديش', 'قديش', 'بكام', 'شحال', 'كم سعره', 'كم سعرها', 'كم حقه', 'كم حقها', 'بكم حقه', 'شلون سعره', 'وش سعره', 'شنو سعره', 'شو سعره', 'سعره', 'سعرها', 'السعر', 'حقه', 'حقها',
                'اسعار', 'أسعار', 'اسعارها', 'أسعارها', 'اسعارهم', 'أسعارهم', 'الاسعار', 'الأسعار', 'تسعيرة', 'تسعير', 'سعرهن', 'اسعارهن', 'تكلفة', 'تكلفته', 'تكلف', 'قيمته', 'قيمة', 'كم سعر', 'price', 'cost',
            ], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini']),
            $this->intent('followup', [
                'طيب', 'هذا', 'هذي', 'هذه', 'ذا', 'اي', 'ايه', 'ايوه', 'لا', 'تمام', 'اوكي', 'ok', 'yes', 'no',
                'شو عن', 'ماذا عن', 'وعن', 'وبالنسبة', 'طيب و', 'شو مع', 'وماذا عن', 'طيب هذا',
            ], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('offers', ['عرض', 'عروض', 'العروض', 'عروضكم', 'خصم', 'خصومات', 'الخصومات', 'تخفيض', 'تخفيضات', 'التخفيضات', 'كود خصم', 'كوبون', 'offer', 'offers', 'discount', 'discounts', 'sale', 'promo', 'promotion'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('budget', ['ارخص', 'الارخص', 'رخيص', 'رخيصة', 'اقل سعر', 'سعر اقل', 'شي ارخص', 'ارخص شي', 'ميزانية', 'ميزانيتي', 'بحدود', 'اقل من', 'تحت', 'غالي', 'مرتفع', 'cheaper', 'cheapest', 'budget', 'affordable', 'under'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('recommended', ['افضل', 'الافضل', 'احسن', 'انصحني', 'وش تنصحني', 'ترشح', 'ترشيح', 'مقترح', 'الاكثر مبيعا', 'مبيعا', 'مميز', 'جديد', 'بست سيلر', 'recommend', 'recommended', 'best', 'bestseller', 'popular'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('comparison', ['قارن', 'مقارنة', 'الفرق', 'وش الفرق', 'ايش الفرق', 'فرق', 'ايهما افضل', 'احسن هذا ولا', 'compare', 'comparison', 'difference', 'versus', 'vs'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('shipping', ['توصيل', 'التوصيل', 'شحن', 'الشحن', 'يوصل', 'توصلون', 'تشحنون', 'متى يوصل', 'كم يوم التوصيل', 'كم ياخذ', 'رسوم التوصيل', 'سعر التوصيل', 'shipping', 'delivery', 'deliver'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('returns', ['استرجاع', 'الاسترجاع', 'ترجيع', 'استبدال', 'الاستبدال', 'تبديل', 'ضمان', 'الضمان', 'سياسة الاسترجاع', 'return', 'returns', 'refund', 'exchange', 'warranty'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('payment', ['دفع', 'الدفع', 'طرق الدفع', 'وسائل الدفع', 'كاش', 'عند الاستلام', 'مدى', 'فيزا', 'تابي', 'تمارا', 'ابل باي', 'تحويل', 'تحويل بنكي', 'payment', 'cod', 'card', 'installment'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('order_status', ['طلبي', 'حالة الطلب', 'وين وصل طلبي', 'وين الطلب', 'تتبع', 'رقم الطلب', 'تاخر الطلب', 'تأخر الطلب', 'order status', 'track order', 'my order'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('complaint', ['مشكلة', 'مشكله', 'خربان', 'تالف', 'مكسور', 'ناقص', 'خطأ', 'غلط', 'سيء', 'تاخر', 'تأخر', 'ما وصل', 'شكوى', 'مستاء', 'زعلان', 'complaint', 'broken', 'damaged', 'missing', 'issue', 'problem', 'late'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('store_info', ['ساعات العمل', 'اوقات العمل', 'الدوام', 'متى تفتحون', 'متى تقفلون', 'مفتوحين', 'موقعكم', 'عنوانكم', 'فرع', 'فروع', 'تواصل', 'رقمكم', 'واتسابكم', 'working hours', 'hours', 'address', 'branch', 'branches', 'contact'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('booking_reschedule', ['تأجيل الحجز', 'تاجيل الحجز', 'تأجيل حجزي', 'تاجيل حجزي', 'تأجيل الموعد', 'تاجيل الموعد', 'تغيير موعد', 'تعديل الحجز', 'تعديل حجزي', 'أؤجل', 'بأجل', 'باجل', 'reschedule', 'postpone booking', 'change appointment'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('booking_cancel', ['إلغاء الحجز', 'الغاء الحجز', 'إلغاء حجزي', 'الغاء حجزي', 'إلغاء الموعد', 'الغاء الموعد', 'الغي الحجز', 'ألغي حجزي', 'الغي الموعد', 'cancel booking', 'cancel appointment'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('booking_inquiry', ['أقرب موعد', 'اقرب موعد', 'المواعيد المتاحة', 'الأوقات المتاحة', 'الاوقات المتاحة', 'متى فاضي', 'متى متاح', 'مواعيدي', 'حجوزاتي', 'تفاصيل حجزي', 'available slots', 'nearest appointment', 'my bookings'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('booking_request', ['حجز', 'حجوزات', 'احجز', 'أحجز', 'موعد', 'مواعيد', 'طاولة', 'ملعب', 'صيانة', 'جلسة', 'استشارة', 'book', 'booking', 'appointment', 'reserve', 'reservation'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('location', ['وين', 'وينكم', 'وين مكانكم', 'وينك', 'فين'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('manner', ['شلون', 'كيف', 'شلونك', 'ازاي'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini']),
            $this->intent('time', ['متى', 'اي وقت', 'أي وقت', 'امتى'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('now', ['الحين', 'دحين', 'الان', 'الآن'], ['saudi', 'kuwaiti', 'emirati', 'qatari']),
            $this->intent('tomorrow', ['بكره', 'بكرة', 'باجر', 'باكر', 'باچر'], ['saudi', 'emirati', 'kuwaiti', 'qatari', 'bahraini']),
            $this->intent('what', ['وش', 'ايش', 'إيش', 'شنو', 'شو'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini']),
        ];
    }

    /**
     * Surface markers that are distinctive enough to hint a Gulf variety.
     * Shared words stay unlabeled so the reply can stay neutral Gulf.
     *
     * @return array<string, string>
     */
    public function dialectMarkers(): array
    {
        return [
            'دحين' => 'saudi',
            'باچر' => 'emirati',
            'شنو' => 'kuwaiti',
        ];
    }

    /**
     * @param  list<string>  $forms
     * @param  list<string>  $dialects
     * @return array{id:string,kind:string,forms:list<string>,dialects:list<string>}
     */
    private function intent(string $id, array $forms, array $dialects): array
    {
        return [
            'id' => $id,
            'kind' => 'intent',
            'forms' => $forms,
            'dialects' => $dialects,
        ];
    }
}
