<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\User;
use App\Support\StoredPicture;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pictures of an organisation and of a person (owner 2026-11-04), cleaned and stored like product
 * pictures (App\Support\StoredPicture):
 *  - the organisation's logo: its admins change it (Settings), its members see it;
 *  - a person's picture: they change their own (Account), it shows instead of their initials to
 *    the people they work with (same organisation or same product) and to cetome staff.
 */
class PictureController extends Controller
{
    private const LOGO_SIDE = 800;

    private const PERSON_SIDE = 400;

    public function __construct(private readonly StoredPicture $pictures) {}

    public function storeLogo(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless($organisation->isAdmin($request->user()), 404);
        $request->validate(['logo' => ['required', 'file', 'max:'.StoredPicture::MAX_KB, 'extensions:png,jpg,jpeg,webp,svg']]);
        $file = $request->file('logo');

        // SVG (sharp at any size): cleaned and kept as SVG, up to 1 MB. Bitmaps: re-encoded as PNG.
        if (StoredPicture::isSvg($file)) {
            $request->validate(['logo' => ['max:'.StoredPicture::SVG_MAX_KB]]);
            $this->pictures->storeSvg($organisation, 'logo_path', 'organisations/'.$organisation->getKey(), $file, 'logo');
        } else {
            $this->pictures->store($organisation, 'logo_path', 'organisations/'.$organisation->getKey(), $file, self::LOGO_SIDE, 'logo');
        }
        activity()->causedBy($request->user())->performedOn($organisation)->log('logo changed');

        return back()->with('status', __('organisation.logo_saved'));
    }

    public function destroyLogo(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless($organisation->isAdmin($request->user()), 404);

        $this->pictures->delete($organisation, 'logo_path');
        activity()->causedBy($request->user())->performedOn($organisation)->log('logo removed');

        return back()->with('status', __('organisation.logo_removed'));
    }

    public function logo(Request $request, Organisation $organisation): StreamedResponse
    {
        $user = $request->user();
        abort_unless($organisation->logo_path && ($organisation->members()->whereKey($user->getKey())->exists() || $user->isStaff()), 404);

        return StoredPicture::response((string) $organisation->logo_path, 'logo.png');
    }

    public function storePicture(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->is_guest, 404);
        $request->validate(['picture' => ['required', 'file', 'max:'.StoredPicture::MAX_KB, 'mimes:png,jpg,jpeg,webp']]);

        $this->pictures->store($user, 'picture_path', 'users/'.$user->getKey(), $request->file('picture'), self::PERSON_SIDE, 'picture');

        return back()->with('status', __('auth.account.picture_saved'));
    }

    public function destroyPicture(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->pictures->delete($user, 'picture_path');

        return back()->with('status', __('auth.account.picture_removed'));
    }

    public function picture(Request $request, User $person): StreamedResponse
    {
        abort_unless($person->picture_path && self::canSee($request->user(), $person), 404);

        return StoredPicture::response((string) $person->picture_path, 'picture.png');
    }

    /** The person themselves, cetome staff, or someone they work with (an organisation or a product). */
    public static function canSee(User $viewer, User $person): bool
    {
        if ($viewer->is($person) || $viewer->isStaff()) {
            return true;
        }
        $organisations = DB::table('organisation_members')->where('user_id', $person->getKey())->pluck('organisation_id');
        if (DB::table('organisation_members')->where('user_id', $viewer->getKey())->whereIn('organisation_id', $organisations)->exists()) {
            return true;
        }
        $products = DB::table('product_members')->where('user_id', $person->getKey())->pluck('product_id')
            ->merge(DB::table('products')->where('owner_id', $person->getKey())->pluck('id'));

        return DB::table('product_members')->where('user_id', $viewer->getKey())->whereIn('product_id', $products)->exists()
            || DB::table('products')->where('owner_id', $viewer->getKey())->whereIn('id', $products)->exists();
    }
}
