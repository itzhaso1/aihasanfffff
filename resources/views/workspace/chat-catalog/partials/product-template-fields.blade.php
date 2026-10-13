<div
    class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4"
    x-show="activeTemplateFields().length > 0"
    x-cloak
>
    <h4 class="mb-3 text-sm font-semibold text-slate-800">حقول القالب</h4>
    <div class="space-y-3">
        <template x-for="(field, index) in activeTemplateFields()" :key="field.key">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-700">
                    <span x-text="field.label"></span>
                    <span x-show="field.required" class="text-red-500">*</span>
                </label>
                <template x-if="field.type === 'boolean'">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" :name="'structured_attributes[' + index + '][attribute_key]'" :value="field.key">
                        <input type="hidden" :name="'structured_attributes[' + index + '][value_type]'" value="boolean">
                        <input type="hidden" :name="'structured_attributes[' + index + '][attribute_value]'" value="0">
                        <input type="checkbox" :name="'structured_attributes[' + index + '][attribute_value]'" value="1" :checked="structuredValues[field.key] === '1' || structuredValues[field.key] === 'true'" class="rounded border-slate-300">
                        <span>نعم</span>
                    </label>
                </template>
                <template x-if="field.type === 'select'">
                    <div>
                        <input type="hidden" :name="'structured_attributes[' + index + '][attribute_key]'" :value="field.key">
                        <input type="hidden" :name="'structured_attributes[' + index + '][value_type]'" value="string">
                        <select :name="'structured_attributes[' + index + '][attribute_value]'" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="">—</option>
                            <template x-for="opt in (field.options || [])" :key="typeof opt === 'object' ? (opt.value ?? opt.label) : opt">
                                <option
                                    :value="typeof opt === 'object' ? (opt.value ?? opt.label) : opt"
                                    x-text="typeof opt === 'object' ? (opt.label ?? opt.value) : opt"
                                    :selected="structuredValues[field.key] === String(typeof opt === 'object' ? (opt.value ?? opt.label) : opt)"
                                ></option>
                            </template>
                        </select>
                    </div>
                </template>
                <template x-if="field.type === 'textarea'">
                    <div>
                        <input type="hidden" :name="'structured_attributes[' + index + '][attribute_key]'" :value="field.key">
                        <input type="hidden" :name="'structured_attributes[' + index + '][value_type]'" value="string">
                        <textarea :name="'structured_attributes[' + index + '][attribute_value]'" rows="3" class="w-full rounded-lg border-slate-300 text-sm" x-bind:value="structuredValues[field.key] || ''"></textarea>
                    </div>
                </template>
                <template x-if="!['boolean','select','textarea'].includes(field.type)">
                    <div>
                        <input type="hidden" :name="'structured_attributes[' + index + '][attribute_key]'" :value="field.key">
                        <input type="hidden" :name="'structured_attributes[' + index + '][value_type]'" :value="field.type === 'number' || field.type === 'money' ? 'number' : (field.type === 'measurement' ? 'measurement' : 'string')">
                        <input
                            :type="field.type === 'number' || field.type === 'money' ? 'number' : 'text'"
                            :step="field.type === 'money' ? '0.01' : '1'"
                            :name="'structured_attributes[' + index + '][attribute_value]'"
                            x-bind:value="structuredValues[field.key] || ''"
                            class="w-full rounded-lg border-slate-300 text-sm"
                        />
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>
