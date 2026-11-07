<?php

namespace App\Http\Controllers;

use App\Models\HomeContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HomeContentController extends Controller
{
    // Public: kailangan din ng Client Site
    public function show()
    {
        $row = HomeContent::where('key', 'home')->first();

        // Walang laman pa -> {} lang; ang frontend na ang gagamit ng defaults
        return response()->json($row?->content ?? new \stdClass());
    }

    // Admin only
    public function update(Request $request)
    {
        $data = $request->validate([
            'hero.eyebrow'       => 'nullable|string|max:120',
            'hero.headline'      => 'required|string|max:200',
            'hero.highlight'     => 'nullable|string|max:120',
            'hero.subtext'       => 'nullable|string|max:500',
            'hero.ctaLabel'      => 'required|string|max:60',
            'hero.linkLabel'     => 'nullable|string|max:60',

            'steps'              => 'required|array|min:1|max:6',
            'steps.*.id'         => 'nullable|string|max:60',
            'steps.*.icon'       => 'required|in:select,calendar,connect,sprout',
            'steps.*.title'      => 'required|string|max:120',
            'steps.*.text'       => 'required|string|max:500',

            'services'           => 'required|array|min:1|max:6',
            'services.*.id'      => 'nullable|string|max:60',
            'services.*.icon'    => 'required|in:chat,blocks,check',
            'services.*.photo'   => 'nullable|string|max:500',
            'services.*.meta'    => 'nullable|string|max:120',
            'services.*.title'   => 'required|string|max:120',
            'services.*.text'    => 'required|string|max:1000',

            'faqs'               => 'required|array|min:1|max:12',
            'faqs.*.id'          => 'nullable|string|max:60',
            'faqs.*.q'           => 'required|string|max:200',
            'faqs.*.a'           => 'required|string|max:1000',

            'contact.email'         => 'required|email|max:120',
            'contact.phone'         => 'required|string|max:40',
            'contact.address'       => 'required|string|max:255',
            'contact.facebook'      => 'nullable|url|max:255',
            'contact.facebookLabel' => 'nullable|string|max:120',
            'contact.hours'         => 'nullable|string|max:200',

            // About page: headline, intro, at ang listahan ng tao (title, show/hide, order)
            'about'                      => 'nullable|array',
            'about.headline'             => 'nullable|string|max:160',
            'about.intro'                => 'nullable|string|max:800',
            'about.members'              => 'nullable|array|max:50',
            'about.members.*.user_id'    => 'required|integer|exists:users,id',
            'about.members.*.title'      => 'nullable|string|max:80',
            'about.members.*.visible'    => 'required|boolean',
        ]);

        $row = HomeContent::where('key', 'home')->first();

        // Kung walang ipinadalang "about" (hal. hindi nakapag-load), huwag burahin ang naka-save na
        if (!isset($data['about'])) {
            $existing = data_get($row?->content, 'about');
            if ($existing) {
                $data['about'] = $existing;
            }
        }

        // $data ay validated fields lang, kaya natatanggal ang kung anu-anong dagdag sa request
        $row = HomeContent::updateOrCreate(
            ['key' => 'home'],
            ['content' => $data, 'updated_by' => $request->user()->id]
        );

        return response()->json($row->content);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048', // 2 MB
        ]);

        $path = $request->file('image')->store('home', 'public');

        return response()->json(['url' => Storage::url($path)]); // /storage/home/xxxx.png
    }
}