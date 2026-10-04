<?php

use App\Domains\Feedback\Models\FeedbackComment;
use App\Domains\Feedback\Models\FeedbackVote;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;
use App\Notifications\FeedbackRemovedNotification;
use App\Notifications\FeedbackSolvedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\NotificationFake;

/*
|--------------------------------------------------------------------------
| El canal de comentarios sobre CONTAPP
|--------------------------------------------------------------------------
|
| Desde la aplicación (panel de la barra superior, JSON): publicar con hasta
| dos imágenes, votar, comentar y borrar lo propio. Desde el backoffice:
| responder como equipo, quitar con motivo y dar por resuelto. Las imágenes
| van al disco de imágenes (acá "s3", de mentira) como comments/{id}_{n}.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('public');
    config(['filesystems.media' => 's3']);
    Notification::fake();
});

/** Una cuenta con su compañía y su licencia, con la sesión iniciada. */
function feedbackUser(string $name = 'Ana Mora', string $email = 'ana@example.com'): User
{
    $user = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => "Compañía de {$name} S.A."],
        ['name' => $name, 'email' => $email, 'password' => 'Secreto#1234'],
    )['user'];

    test()->actingAs($user);

    return $user;
}

/** Una imagen de verdad (GD), PNG o JPG según el nombre. */
function feedbackImage(string $name = 'captura.png', int $width = 320, int $height = 200): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 90, 160));

    ob_start();
    str_ends_with($name, '.png') ? imagepng($image) : imagejpeg($image, null, 90);

    return UploadedFile::fake()->createWithContent($name, ob_get_clean());
}

/** Una publicación de $author, ya creada. */
function feedbackPost(User $author, string $body = 'Sería bueno exportar a PDF el balance.', array $attributes = []): FeedbackComment
{
    return FeedbackComment::create(['user_id' => $author->id, 'body' => $body, 'status' => FeedbackComment::OPEN, ...$attributes]);
}

/** Vuelve a la aplicación después de una request del backoffice (ver leaveBackoffice). */
function leaveFeedbackBackoffice(): void
{
    Auth::guard('propietario')->logout();
    Auth::shouldUse('web');
}

it('publica con dos imágenes, que quedan en el bucket como comments/{id}_{n}', function () {
    feedbackUser();

    $response = $this->postJson(route('feedback.store'), [
        'body' => '  Me gustaría un reporte de flujo de caja.  ',
        'images' => [feedbackImage('una.png'), feedbackImage('dos.jpg')],
    ])->assertCreated();

    $post = FeedbackComment::sole();

    expect($post->body)->toBe('Me gustaría un reporte de flujo de caja.')
        ->and($post->imagePaths())->toBe(["comments/{$post->id}_1.png", "comments/{$post->id}_2.jpg"]);

    Storage::disk('s3')->assertExists(["comments/{$post->id}_1.png", "comments/{$post->id}_2.jpg"]);

    $response->assertJsonPath('post.id', $post->id)
        ->assertJsonPath('post.author.name', 'Ana Mora')
        ->assertJsonPath('post.is_mine', true)
        ->assertJsonPath('post.score', 0)
        ->assertJsonCount(2, 'post.images');
});

it('achica las imágenes grandes y conserva las chicas', function () {
    feedbackUser();

    $this->postJson(route('feedback.store'), [
        'body' => 'Captura grande',
        'images' => [feedbackImage('grande.png', 3000, 1500), feedbackImage('chica.png', 300, 100)],
    ])->assertCreated();

    $post = FeedbackComment::sole();

    expect(getimagesizefromstring(Storage::disk('s3')->get("comments/{$post->id}_1.png"))[0])->toBe(2000)
        ->and(getimagesizefromstring(Storage::disk('s3')->get("comments/{$post->id}_2.png"))[0])->toBe(300);
});

it('no acepta más de dos imágenes, ni algo que no sea una imagen, ni un texto vacío', function (array $payload, string $field) {
    feedbackUser();

    $this->postJson(route('feedback.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(FeedbackComment::count())->toBe(0);
    expect(Storage::disk('s3')->allFiles())->toBe([]);
})->with([
    'tres imágenes' => [fn () => ['body' => 'Hola', 'images' => [feedbackImage(), feedbackImage(), feedbackImage()]], 'images'],
    'un PDF' => [fn () => ['body' => 'Hola', 'images' => [UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf')]], 'images.0'],
    'un video' => [fn () => ['body' => 'Hola', 'images' => [UploadedFile::fake()->create('video.mp4', 200, 'video/mp4')]], 'images.0'],
    'sin texto' => [fn () => ['body' => '   '], 'body'],
    'texto de más' => [fn () => ['body' => str_repeat('a', FeedbackComment::MAX_BODY + 1)], 'body'],
]);

it('lista lo más votado primero, o lo más nuevo, sin lo resuelto ni lo quitado', function () {
    $ana = feedbackUser();
    $beto = User::factory()->create(['status' => 'active']);

    $old = feedbackPost($beto, 'Vieja y votada', ['score' => 5]);
    $new = feedbackPost($beto, 'Nueva sin votos');
    $negative = feedbackPost($beto, 'Votada en contra', ['score' => -2]);
    feedbackPost($beto, 'Resuelta', ['status' => FeedbackComment::SOLVED, 'score' => 50]);
    feedbackPost($beto, 'Quitada', ['status' => FeedbackComment::REMOVED, 'score' => 50]);
    FeedbackVote::create(['comment_id' => $old->id, 'user_id' => $ana->id, 'value' => 1]);

    $this->getJson(route('feedback.index'))
        ->assertOk()
        ->assertJsonPath('posts.*.id', [$old->id, $new->id, $negative->id])
        ->assertJsonPath('posts.0.my_vote', 1)
        ->assertJsonPath('posts.1.my_vote', 0)
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('limits.max_images', 2);

    $this->getJson(route('feedback.index', ['sort' => 'new']))
        ->assertJsonPath('posts.*.id', [$negative->id, $new->id, $old->id]);
});

it('del autor muestra el nombre y la foto, nunca el correo', function () {
    feedbackUser();
    $beto = User::factory()->create(['name' => 'Beto Solís', 'email' => 'beto-privado@example.com', 'status' => 'active']);
    feedbackPost($beto);

    $response = $this->getJson(route('feedback.index'))->assertJsonPath('posts.0.author.name', 'Beto Solís');

    expect($response->getContent())->not->toContain('beto-privado@example.com');
});

it('vota a favor, cambia a en contra y quita el voto', function () {
    feedbackUser();
    $beto = User::factory()->create(['status' => 'active']);
    $carla = User::factory()->create(['status' => 'active']);
    $post = feedbackPost($beto);
    FeedbackVote::create(['comment_id' => $post->id, 'user_id' => $carla->id, 'value' => 1]);
    $post->update(['score' => 1]);

    $this->putJson(route('feedback.vote', $post), ['value' => 1])->assertOk()->assertJson(['score' => 2, 'my_vote' => 1]);
    $this->putJson(route('feedback.vote', $post), ['value' => -1])->assertOk()->assertJson(['score' => 0, 'my_vote' => -1]);
    $this->putJson(route('feedback.vote', $post), ['value' => 0])->assertOk()->assertJson(['score' => 1, 'my_vote' => 0]);

    expect(FeedbackVote::where('comment_id', $post->id)->count())->toBe(1);
});

it('no se vota lo propio, ni algo que no sea 1, 0 o -1', function () {
    $ana = feedbackUser();
    $own = feedbackPost($ana);
    $other = feedbackPost(User::factory()->create(['status' => 'active']));

    $this->putJson(route('feedback.vote', $own), ['value' => 1])->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->putJson(route('feedback.vote', $other), ['value' => 5])->assertUnprocessable();

    expect(FeedbackVote::count())->toBe(0);
});

it('las respuestas no se votan, y lo resuelto ya no se vota ni se comenta', function () {
    feedbackUser();
    $beto = User::factory()->create(['status' => 'active']);
    $post = feedbackPost($beto);
    $reply = FeedbackComment::create(['parent_id' => $post->id, 'user_id' => $beto->id, 'body' => 'Una respuesta', 'status' => 'open']);
    $solved = feedbackPost($beto, 'Resuelta', ['status' => FeedbackComment::SOLVED]);

    $this->putJson(route('feedback.vote', $reply), ['value' => 1])->assertNotFound();
    $this->putJson(route('feedback.vote', $solved), ['value' => 1])->assertNotFound();
    $this->postJson(route('feedback.replies.store', $solved), ['body' => 'Hola'])->assertNotFound();
    $this->getJson(route('feedback.replies', $solved))->assertNotFound();
});

it('comenta la publicación de otro, con imágenes, y lista las respuestas en orden', function () {
    $ana = feedbackUser();
    $beto = User::factory()->create(['status' => 'active']);
    $post = feedbackPost($beto);
    FeedbackComment::create(['parent_id' => $post->id, 'user_id' => $beto->id, 'body' => 'Primera', 'status' => 'open']);
    FeedbackComment::create(['parent_id' => $post->id, 'user_id' => $beto->id, 'body' => 'Quitada', 'status' => FeedbackComment::REMOVED]);

    $response = $this->postJson(route('feedback.replies.store', $post), [
        'body' => '¡Yo también lo necesito!',
        'images' => [feedbackImage('mia.png')],
    ])->assertCreated();

    $reply = FeedbackComment::where('user_id', $ana->id)->sole();

    expect($reply->parent_id)->toBe($post->id)
        ->and($reply->imagePaths())->toBe(["comments/{$reply->id}_1.png"]);
    $response->assertJsonPath('reply.is_mine', true);

    $this->getJson(route('feedback.replies', $post))
        ->assertOk()
        ->assertJsonPath('replies.*.body', ['Primera', '¡Yo también lo necesito!']);

    $this->getJson(route('feedback.index'))->assertJsonPath('posts.0.replies_count', 2);
});

it('el autor borra su publicación, con las respuestas y las imágenes de todas', function () {
    $ana = feedbackUser();

    $this->postJson(route('feedback.store'), ['body' => 'Mía', 'images' => [feedbackImage('a.png')]])->assertCreated();
    $post = FeedbackComment::sole();

    $beto = User::factory()->create(['status' => 'active']);
    $this->actingAs($beto);
    $this->postJson(route('feedback.replies.store', $post), ['body' => 'De Beto', 'images' => [feedbackImage('b.png')]])->assertCreated();
    $this->putJson(route('feedback.vote', $post), ['value' => 1])->assertOk();

    // Beto no puede borrar la publicación de Ana.
    $this->deleteJson(route('feedback.destroy', $post))->assertForbidden();

    $this->actingAs($ana);
    $this->deleteJson(route('feedback.destroy', $post))->assertNoContent();

    expect(FeedbackComment::count())->toBe(0)
        ->and(FeedbackVote::count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('el autor borra su respuesta sin tocar la publicación', function () {
    $ana = feedbackUser();
    $post = feedbackPost(User::factory()->create(['status' => 'active']));

    $this->postJson(route('feedback.replies.store', $post), ['body' => 'Me equivoqué'])->assertCreated();
    $reply = FeedbackComment::where('user_id', $ana->id)->sole();

    $this->deleteJson(route('feedback.destroy', $reply))->assertNoContent();

    expect(FeedbackComment::find($reply->id))->toBeNull()
        ->and(FeedbackComment::find($post->id))->not->toBeNull();
});

it('exige sesión', function () {
    $this->getJson(route('feedback.index'))->assertUnauthorized();
    $this->postJson(route('feedback.store'), ['body' => 'Hola'])->assertUnauthorized();
});

it('con la licencia vencida se sigue pudiendo escribir y votar', function () {
    $ana = feedbackUser();
    License::where('superuser_id', $ana->id)->update(['expires_at' => now()->subDay()->format('Y-m-d')]);
    $post = feedbackPost(User::factory()->create(['status' => 'active']));

    $this->postJson(route('feedback.store'), ['body' => 'Desde la gracia'])->assertCreated();
    $this->putJson(route('feedback.vote', $post), ['value' => 1])->assertOk();
    $this->postJson(route('feedback.replies.store', $post), ['body' => 'También'])->assertCreated();
});

// ── Backoffice ─────────────────────────────────────────────────────────────

it('el backoffice ve las publicaciones abiertas, con el correo del autor y sus respuestas', function () {
    $beto = User::factory()->create(['name' => 'Beto', 'email' => 'beto@example.com', 'status' => 'active']);
    $post = feedbackPost($beto, 'Más reportes', ['score' => 3]);
    FeedbackComment::create(['parent_id' => $post->id, 'user_id' => $beto->id, 'body' => 'Respuesta', 'status' => 'open']);
    feedbackPost($beto, 'Ya resuelta', ['status' => FeedbackComment::SOLVED]);

    loginAsPropietario();

    $this->get(route('backoffice.feedback.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Backoffice/Feedback/Index')
            ->has('posts.data', 1)
            ->where('posts.data.0.author.email', 'beto@example.com')
            ->where('posts.data.0.replies.0.body', 'Respuesta')
            ->where('counts.open', 1)
            ->where('counts.solved', 1));

    $this->get(route('backoffice.feedback.index', ['status' => 'solved']))
        ->assertInertia(fn ($page) => $page->where('posts.data.0.body', 'Ya resuelta'));
});

it('el backoffice responde como equipo de CONTAPP, y la respuesta se ve en la aplicación', function () {
    $beto = User::factory()->create(['status' => 'active']);
    $post = feedbackPost($beto);
    $propietario = loginAsPropietario(['name' => 'Kevin']);

    $this->post(route('backoffice.feedback.reply', $post), [
        'body' => 'Ya está en la lista de lo próximo.',
        'images' => [feedbackImage('plan.png')],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $reply = FeedbackComment::whereNotNull('propietario_id')->sole();
    expect($reply->propietario_id)->toBe($propietario->id)
        ->and($reply->imagePaths())->toBe(["comments/{$reply->id}_1.png"]);

    leaveFeedbackBackoffice();
    $this->actingAs($beto);

    $this->getJson(route('feedback.replies', $post))
        ->assertJsonPath('replies.0.author.name', 'Equipo '.config('app.name'))
        ->assertJsonPath('replies.0.author.is_team', true);
});

it('el backoffice quita una publicación: deja de verse, se borran sus imágenes y el autor recibe el motivo', function () {
    $ana = feedbackUser();
    $this->postJson(route('feedback.store'), ['body' => 'Algo fuera de lugar', 'images' => [feedbackImage('x.png')]])->assertCreated();
    $post = FeedbackComment::sole();

    $propietario = loginAsPropietario();

    $this->post(route('backoffice.feedback.remove', $post), ['reason' => 'No es un comentario sobre CONTAPP.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'ana@example.com'));

    $post->refresh();
    expect($post->status)->toBe(FeedbackComment::REMOVED)
        ->and($post->removed_by)->toBe($propietario->id)
        ->and($post->removal_reason)->toBe('No es un comentario sobre CONTAPP.')
        ->and(Storage::disk('s3')->allFiles())->toBe([]);

    Notification::assertSentTo($ana, FeedbackRemovedNotification::class, function ($notification) use ($ana) {
        $mail = $notification->toMail($ana);

        return $mail->viewData['reason'] === 'No es un comentario sobre CONTAPP.'
            && $mail->viewData['what'] === 'tu publicación';
    });

    leaveFeedbackBackoffice();
    $this->actingAs($ana);
    $this->getJson(route('feedback.index'))->assertJsonCount(0, 'posts');
});

it('el backoffice quita una respuesta, y la publicación sigue', function () {
    $beto = User::factory()->create(['status' => 'active']);
    $post = feedbackPost(User::factory()->create(['status' => 'active']));
    $reply = FeedbackComment::create(['parent_id' => $post->id, 'user_id' => $beto->id, 'body' => 'Spam', 'status' => 'open']);

    loginAsPropietario();
    $this->post(route('backoffice.feedback.remove', $reply), ['reason' => 'Publicidad.'])->assertSessionHasNoErrors();

    expect($reply->fresh()->status)->toBe(FeedbackComment::REMOVED)
        ->and($post->fresh()->status)->toBe(FeedbackComment::OPEN);

    Notification::assertSentTo($beto, FeedbackRemovedNotification::class, fn ($n) => $n->toMail($beto)->viewData['what'] === 'tu comentario');
});

it('quitar exige un motivo', function () {
    $post = feedbackPost(User::factory()->create(['status' => 'active']));

    loginAsPropietario();
    $this->post(route('backoffice.feedback.remove', $post), ['reason' => ''])->assertSessionHasErrors('reason');

    expect($post->fresh()->status)->toBe(FeedbackComment::OPEN);
    Notification::assertNothingSent();
});

it('el backoffice da por resuelta una publicación y le agradece al autor', function () {
    $beto = User::factory()->create(['name' => 'Beto', 'status' => 'active']);
    $post = feedbackPost($beto);
    $propietario = loginAsPropietario();

    $this->post(route('backoffice.feedback.solve', $post), ['note' => 'Ya está disponible en Reportes.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $post->refresh();
    expect($post->status)->toBe(FeedbackComment::SOLVED)
        ->and($post->solved_by)->toBe($propietario->id);

    Notification::assertSentTo($beto, FeedbackSolvedNotification::class, fn ($n) => $n->toMail($beto)->viewData['note'] === 'Ya está disponible en Reportes.');

    // Y se puede reabrir, sin avisarle a nadie.
    $this->post(route('backoffice.feedback.reopen', $post))->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe(FeedbackComment::OPEN);
    Notification::assertSentToTimes($beto, FeedbackSolvedNotification::class, 1);
});

it('una respuesta no se da por resuelta', function () {
    $post = feedbackPost(User::factory()->create(['status' => 'active']));
    $reply = FeedbackComment::create(['parent_id' => $post->id, 'user_id' => $post->user_id, 'body' => 'R', 'status' => 'open']);

    loginAsPropietario();
    $this->post(route('backoffice.feedback.solve', $reply))->assertSessionHasErrors('note');

    expect($reply->fresh()->status)->toBe(FeedbackComment::OPEN);
});

it('si el correo falla, la acción igual se hace y el mensaje lo dice', function () {
    $beto = User::factory()->create(['email' => 'beto@example.com', 'status' => 'active']);
    $post = feedbackPost($beto);

    Notification::swap(new class extends NotificationFake
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('SMTP caído');
        }
    });

    loginAsPropietario();
    $this->post(route('backoffice.feedback.solve', $post))
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'no se pudo enviar'));

    expect($post->fresh()->status)->toBe(FeedbackComment::SOLVED);
});

it('solo el backoffice modera', function () {
    $ana = feedbackUser();
    $post = feedbackPost(User::factory()->create(['status' => 'active']));

    $this->get(route('backoffice.feedback.index'))->assertRedirect(route('backoffice.login'));
    $this->post(route('backoffice.feedback.remove', $post), ['reason' => 'x'])->assertRedirect(route('backoffice.login'));
    $this->post(route('backoffice.feedback.solve', $post))->assertRedirect(route('backoffice.login'));

    expect($post->fresh()->status)->toBe(FeedbackComment::OPEN);
});
