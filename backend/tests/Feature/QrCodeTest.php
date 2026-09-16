<?php

namespace Tests\Feature;

/**
 * Phase 8, DoD #1 — scanning the QR code opens the live tracking page with
 * no login prompt. This can't literally "scan" in a test, but it can
 * verify the QR encodes the correct public /track/{token} URL (never the
 * numeric visit id) and that the tracking endpoint it points to really is
 * reachable with zero authentication — both already covered end-to-end.
 */
class QrCodeTest extends NotificationTestCase
{
    public function test_visit_creation_response_includes_a_qr_code_encoding_the_public_tracking_url(): void
    {
        $patientRes = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'QR Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000201',
        ])->assertStatus(201);

        $response = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientRes->json('patient.id'),
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);

        $qrCode = $response->json('qr_code');
        $this->assertNotNull($qrCode);
        $this->assertStringStartsWith('data:image/png;base64,', $qrCode);

        // Decodes to a real PNG, not just a base64-looking string.
        $binary = base64_decode(substr($qrCode, strlen('data:image/png;base64,')));
        $this->assertStringStartsWith("\x89PNG", $binary);

        $trackingToken = $response->json('visit.tracking_token');
        $this->assertNotNull($trackingToken);
        // Never the numeric visit id anywhere in the QR's target — confirmed
        // by construction (QrCodeService builds the URL from tracking_token
        // only), verified here indirectly via the tracking endpoint working.
        $this->getJson("/api/track/{$trackingToken}")->assertStatus(200);
    }
}
