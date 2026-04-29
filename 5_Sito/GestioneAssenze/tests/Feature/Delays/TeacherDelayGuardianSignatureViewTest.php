<?php

namespace Tests\Feature\Delays;

use App\Jobs\Mail\GuardianDelaySignatureMail;
use App\Models\Delay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

class TeacherDelayGuardianSignatureViewTest extends DelayFeatureTestCase
{
    public function test_teacher_delay_detail_exposes_guardian_signature_metadata(): void
    {
        [
            'teacher' => $teacher,
            'delay' => $delay,
        ] = $this->createSignedDelay('detail-view');

        $response = $this->actingAs($teacher)->get(route('teacher.delays.show', $delay));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Teacher/DelayDetail')
            ->where('item.delay_id', $delay->id)
            ->where('item.firma_tutore_richiesta', true)
            ->where('item.firma_tutore_presente', true)
            ->where('item.guardian_signature.guardian_name', 'Paolo Rossi')
            ->where('item.guardian_signature.source', 'delay')
            ->where('item.guardian_signature.source_label', 'Firma richiesta ritardo')
            ->where(
                'item.guardian_signature.viewer_url',
                route('teacher.delays.guardian-signature.view', $delay)
            )
        );
    }

    public function test_teacher_can_view_guardian_delay_signature_file(): void
    {
        [
            'teacher' => $teacher,
            'delay' => $delay,
        ] = $this->createSignedDelay('file-view');

        $response = $this->actingAs($teacher)->get(
            route('teacher.delays.guardian-signature.view', $delay)
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'image/png');
    }

    /**
     * @return array{
     *     teacher:\App\Models\User,
     *     delay:\App\Models\Delay
     * }
     */
    private function createSignedDelay(string $suffix): array
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-22 12:59:00'));

        $this->createDelaySetting(
            guardianSignatureRequired: true,
            deadlineModeActive: true
        );

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors($suffix);

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-04-22',
            'delay_minutes' => 10,
            'motivation' => 'Autobus perso',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Ritardo non della direzione',
        ])->assertSessionHasNoErrors();

        $plainToken = null;
        Mail::assertSent(GuardianDelaySignatureMail::class, function (GuardianDelaySignatureMail $mail) use (
            $guardian,
            &$plainToken
        ) {
            if (! $mail->hasTo($guardian->email)) {
                return false;
            }

            $plainToken = $this->extractTokenFromSignatureUrl($mail->signatureUrl);

            return true;
        });

        $this->assertNotNull($plainToken);

        $this->post(route('guardian.delays.signature.store', ['token' => $plainToken]), [
            'full_name' => 'Paolo Rossi',
            'consent' => 1,
            'signature_data' => $this->validSignatureDataUrl(),
        ])->assertSessionHasNoErrors();

        return [
            'teacher' => $teacher,
            'delay' => $delay->fresh(),
        ];
    }
}
