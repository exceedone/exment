{{-- Raw-writer drain / exclusion attestation: every box starts unchecked; the administrator states what was done. --}}
<fieldset class="border p-2 mb-2">
    <legend class="fs-6">{{ exmtrans('storage_conversion.attestation') }}</legend>
    <p class="text-muted"><small>{{ exmtrans('storage_conversion.attestation_help') }}</small></p>
    @foreach($codes as $code)
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="attest_{{ $code }}" name="attest_{{ $code }}" value="1">
            <label class="form-check-label" for="attest_{{ $code }}">{{ exmtrans('storage_conversion.attest_' . $code) }}</label>
        </div>
    @endforeach
    <label class="form-label mt-2" for="attest_measures">{{ exmtrans('storage_conversion.attest_measures') }}</label>
    <textarea id="attest_measures" name="attest_measures" class="form-control" rows="2" required></textarea>
</fieldset>
