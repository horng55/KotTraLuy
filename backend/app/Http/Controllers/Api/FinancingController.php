<?php

namespace App\Http\Controllers\Api;

use App\Models\FinancingProfile;
use App\Models\FinancingDocument;
use App\Models\PreparationPack;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\Request;

class FinancingController
{
    public function overview(Request $request)
    {
        $business = $request->user()->business;
        $profile = FinancingProfile::firstOrCreate(['business_id' => $business->id]);
        $documentKeys = ['identity_document', 'registration_tax_information', 'bank_statements', 'business_financial_records', 'existing_debt_information'];
        $documents = collect($documentKeys)->map(function ($key) use ($business) {
            return FinancingDocument::firstOrCreate(['business_id' => $business->id, 'document_key' => $key], ['availability' => 'not_available', 'verification_status' => 'not_verified']);
        });
        $recordsSince = now()->subMonths(3)->startOfDay();

        $sales = Sale::where('business_id', $business->id)->where('sold_at', '>=', $recordsSince)->count();
        $purchases = Purchase::where('business_id', $business->id)->where('purchase_date', '>=', $recordsSince->toDateString())->count();

        $bankStatementsAvailable = $documents->firstWhere('document_key', 'bank_statements')?->availability === 'available';
        $registrationProvided = $business->registration_status === 'registered'
            || $profile->business_registration_status === 'provided';

        return response()->json([
            'business_profile' => $business,
            'profile' => $profile,
            'documents' => $documents,
            'records' => ['sales' => $sales, 'purchases' => $purchases, 'months_available' => $sales || $purchases ? 3 : 0],
            'checklist' => [
                ['key' => 'profile', 'label' => 'Business profile completed', 'status' => $business->name && $business->activity && $business->province ? 'matched' : 'missing'],
                ['key' => 'records', 'label' => 'Recent business records available', 'status' => ($sales || $purchases) ? 'matched' : 'missing'],
                ['key' => 'bank_statement', 'label' => 'Bank statement available', 'status' => $bankStatementsAvailable || $profile->bank_statement_status === 'provided' ? 'matched' : 'missing'],
                ['key' => 'registration', 'label' => 'Business registration information reviewed', 'status' => $registrationProvided ? 'matched' : 'unknown'],
                ['key' => 'collateral', 'label' => 'Collateral acceptance confirmed', 'status' => $profile->collateral_status === 'confirmed' ? 'matched' : 'unknown'],
            ],
        ]);
    }

    public function products(Request $request)
    {
        $business = $request->user()->business;
        $profile = FinancingProfile::firstOrCreate(['business_id' => $business->id]);
        $documents = FinancingDocument::where('business_id', $business->id)->get()->keyBy('document_key');
        return response()->json(collect(config('financing_products'))->map(fn ($product) => [...$product, 'evaluation' => collect($product['criteria'])->map(fn ($criterion) => [...$criterion, 'status' => $this->evaluate($criterion, $business, $profile, $documents)])->values()]));
    }

    public function saveProfile(Request $request)
    {
        $data = $request->validate([
            'requested_amount' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'in:USD,KHR'],
            'purpose' => ['nullable', 'string', 'max:120'], 'requested_timing' => ['nullable', 'string', 'max:80'], 'business_registration_status' => ['nullable', 'in:unknown,provided'],
            'bank_statement_status' => ['nullable', 'in:missing,provided'], 'collateral_status' => ['nullable', 'in:unknown,confirmed,not_available'], 'notes' => ['nullable', 'string'],
        ]);
        $profile = FinancingProfile::updateOrCreate(['business_id' => $request->user()->business_id], $data);
        return response()->json(['profile' => $profile]);
    }

    public function updateBusiness(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'activity' => 'nullable|string|max:160', 'province' => 'nullable|string|max:120', 'operating_started_on' => 'nullable|date', 'registration_status' => 'required|in:unknown,informal,registered']);
        $business = $request->user()->business;
        $business->update([...$data, 'profile_source' => 'self_reported']);
        return response()->json(['business' => $business->fresh()]);
    }

    public function updateDocument(Request $request, string $documentKey)
    {
        abort_unless(in_array($documentKey, ['identity_document', 'registration_tax_information', 'bank_statements', 'business_financial_records', 'existing_debt_information'], true), 404);
        $data = $request->validate(['availability' => 'required|in:not_available,available', 'verification_status' => 'required|in:not_verified,verified', 'notes' => 'nullable|string']);
        $document = FinancingDocument::updateOrCreate(['business_id' => $request->user()->business_id, 'document_key' => $documentKey], $data);
        return response()->json(['document' => $document]);
    }

    public function packs(Request $request)
    {
        return response()->json(PreparationPack::where('business_id', $request->user()->business_id)->latest()->get());
    }

    public function createPack(Request $request)
    {
        $data = $request->validate(['product_key' => 'nullable|string']);
        $business = $request->user()->business;
        $profile = FinancingProfile::where('business_id', $business->id)->first();
        abort_if(!$profile || !$profile->requested_amount || !$business->name, 422, 'Complete a business profile and funding request before creating a pack.');
        $product = collect(config('financing_products'))->firstWhere('key', $data['product_key'] ?? null);
        $documents = FinancingDocument::where('business_id', $business->id)->get()->map(fn ($document) => $document->only(['document_key', 'availability', 'verification_status', 'notes']));
        $pack = PreparationPack::create(['business_id' => $business->id, 'status' => 'draft', 'product_key' => $product['key'] ?? null, 'snapshot' => ['created_at' => now()->toIso8601String(), 'business' => $business->only(['name', 'activity', 'province', 'operating_started_on', 'registration_status']), 'funding_request' => $profile->only(['requested_amount', 'currency', 'purpose', 'requested_timing']), 'product' => $product, 'documents' => $documents]]);
        return response()->json(['pack' => $pack], 201);
    }

    private function evaluate(array $criterion, $business, $profile, $documents): string
    {
        return match ($criterion['type']) {
            'bank_confirmation' => 'bank_confirmation_needed',
            'profile' => $business->name && $business->business_type ? 'matched' : 'information_missing',
            'document' => ($documents[$criterion['value']]?->availability ?? 'not_available') === 'available' ? 'matched' : 'information_missing',
            'operating_months' => $business->operating_started_on && $business->operating_started_on->diffInMonths(now()) >= $criterion['value'] ? 'matched' : ($business->operating_started_on ? 'requirement_mismatch' : 'information_missing'),
            default => 'bank_confirmation_needed',
        };
    }
}
