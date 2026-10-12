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
            $this->intent('request', ['ابي', 'ابغى', 'ابغا', 'ابيك', 'ودي', 'ابيك تشوف', 'ابي اشوف', 'عطني', 'ورني', 'ابغى اشوف'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('availability', ['عندكم', 'عندك', 'متوفر', 'موجود', 'فيه', 'في', 'عدكم'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('price', ['بكم', 'كم', 'كم سعره', 'كم سعرها', 'كم حقه', 'كم حقها', 'بكم حقه', 'شلون سعره', 'وش سعره', 'شنو سعره', 'شو سعره', 'سعره', 'سعرها', 'السعر', 'حقه', 'حقها'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini']),
            $this->intent('followup', ['طيب', 'هذا', 'هذي', 'هذه', 'ذا', 'اي', 'ايه', 'ايوه', 'لا', 'تمام', 'اوكي'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('location', ['وين', 'وينكم', 'وين مكانكم', 'وينك'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
            $this->intent('manner', ['شلون', 'كيف', 'شلونك'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini']),
            $this->intent('time', ['متى', 'اي وقت', 'أي وقت'], ['saudi', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani']),
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
