{{--
    Settings, for the organisation's admins: its logo, its name and the manufacturer named on the
    declaration of conformity (the same fields as a product's Declaration step,
    OrganisationLegalController). The logo shows in REDact and on public product pages when the
    manufacturer ticks it there.
--}}
<section class="a-panel" aria-label="{{ __('organisation.card') }}">
    <div class="a-panel-body">
        <div class="grid gap-5 md:grid-cols-[10rem_minmax(0,1fr)]">
            <x-picture-box
                :src="$organisation->logo_path ? route('organisations.logo', $organisation) : null"
                :store="route('organisations.logo.store', $organisation)"
                :destroy="route('organisations.logo.destroy', $organisation)"
                field="logo"
                accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg"
                :add="__('organisation.add_logo')"
                :alt="__('organisation.logo_alt', ['name' => $organisation->name])"
                :confirm="__('organisation.remove_logo_confirm')"
                :help="__('organisation.logo_help')" />

            <form method="POST" action="{{ route('organisations.legal.update', $organisation) }}" class="a-stack gap-4!" id="manufacturer">
                @csrf
                @method('PUT')
                <input type="hidden" name="back" value="{{ route('organisations.settings', $organisation) }}">
                <div class="a-field">
                    <label for="org-name" class="a-label">{{ __('organisation.name') }}</label>
                    <input id="org-name" name="name" class="a-input" required maxlength="160" value="{{ old('name', $organisation->name) }}" @error('name') aria-invalid="true" @enderror>
                    <p class="a-help">{{ __('organisation.name_help') }}</p>
                    @error('name')<p class="a-error">{{ $message }}</p>@enderror
                </div>

                <div class="a-stack gap-3!">
                    <h3 class="a-micro">{{ __('organisation.manufacturer') }}</h3>
                    <div class="a-form-grid">
                        <div class="a-field">
                            <label for="org-legal" class="a-label">{{ __('declaration.manufacturer.legal_name') }}</label>
                            <input id="org-legal" name="legal_name" class="a-input" maxlength="200" value="{{ old('legal_name', $organisation->legal_name) }}" placeholder="{{ $organisation->name }}">
                        </div>
                        <div class="a-field">
                            <label for="org-country" class="a-label">{{ __('declaration.manufacturer.country') }}</label>
                            <input id="org-country" name="country" class="a-input" maxlength="80" value="{{ old('country', $organisation->country) }}">
                        </div>
                        <div class="a-field a-span-all">
                            <label for="org-address" class="a-label">{{ __('declaration.manufacturer.address') }}</label>
                            <textarea id="org-address" name="address" class="a-input" rows="2" maxlength="1000">{{ old('address', $organisation->address) }}</textarea>
                        </div>
                        <div class="a-field">
                            <label for="org-contact" class="a-label">{{ __('declaration.manufacturer.security_contact') }}</label>
                            <input id="org-contact" name="security_contact" class="a-input" maxlength="300" value="{{ old('security_contact', $organisation->security_contact) }}" @error('security_contact') aria-invalid="true" @enderror>
                            <p class="a-help">{{ __('declaration.manufacturer.security_contact_help') }}</p>
                            @error('security_contact')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="a-field">
                            <label for="org-rep" class="a-label">{{ __('declaration.manufacturer.rep_name') }}</label>
                            <input id="org-rep" name="authorised_rep_name" class="a-input" maxlength="200" value="{{ old('authorised_rep_name', $organisation->authorised_rep_name) }}">
                        </div>
                        <div class="a-field a-span-all">
                            <label for="org-rep-address" class="a-label">{{ __('declaration.manufacturer.rep_address') }}</label>
                            <textarea id="org-rep-address" name="authorised_rep_address" class="a-input" rows="2" maxlength="1000">{{ old('authorised_rep_address', $organisation->authorised_rep_address) }}</textarea>
                        </div>
                    </div>
                    <p class="a-help">{{ __('organisation.same_as_declaration') }}</p>
                </div>
                <div><button type="submit" class="a-btn a-btn-primary"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button></div>
            </form>
        </div>
    </div>
</section>
