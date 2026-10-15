<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CivicSubject;
use App\Entity\SubjectMedia;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Subject\MediaStatus;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use App\Tests\Support\AltchaPayload;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Step 3 M.1 (ADR-0011): fotografiile subiectelor - re-encodare fără metadate, tip după conținut, limite,
 * moderare individuală, servire prin X-Accel-Redirect după vizibilitate, scoatere cu ștergerea fișierelor.
 */
final class SubjectMediaTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';
    private const FIXTURES = __DIR__.'/../Fixtures/media/';

    private KernelBrowser $client;
    private Connection $db;
    private string $mediaDir;
    private string $tmpDir;
    /** @var array<string, CivicSubject> */
    private array $s = [];
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $this->db = $c->get(Connection::class);
        Database::reset($this->db);
        $fs = new Filesystem();
        $this->mediaDir = $c->getParameter('kernel.project_dir').'/var/test-media';
        $this->tmpDir = $c->getParameter('kernel.project_dir').'/var/test-uploads';
        $fs->remove([$this->mediaDir, $this->tmpDir]);
        $fs->mkdir($this->tmpDir);

        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        // ion: cont nou, sub moderare (ADR-0006); vlad: 3 contribuții aprobate, publică direct.
        $users = [
            'admin' => new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]),
            'ion' => new User('ion@validez.test', 'Ion'),
            'vlad' => new User('vlad@validez.test', 'Vlad'),
            'maria' => new User('maria@validez.test', 'Maria'),
        ];
        foreach ($users as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $node = new TreeNode('Drumuri', $users['admin'], null, 0);
        $em->persist($node);
        $subject = function (string $key, User $author) use ($em, $node): CivicSubject {
            $s = new CivicSubject($node, $author, SubjectType::ISSUE, 'Groapa '.$key, 'Descriere '.$key);
            $s->assignSlug('groapa-'.$key);
            $em->persist($s);
            return $this->s[$key] = $s;
        };
        $subject('ion', $users['ion']);
        $subject('vlad', $users['vlad']);
        for ($i = 1; $i <= 3; ++$i) {
            $subject('vlad-aprobat-'.$i, $users['vlad'])->markApprovedContribution();
        }
        $subject('ascuns', $users['vlad'])->moderate(null, SubjectVisibility::HIDDEN);
        $em->flush();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->mediaDir, $this->tmpDir]);
        parent::tearDown();
    }

    private function token(string $who): string
    {
        if (!isset($this->tokens[$who])) {
            $body = ['email' => "$who@validez.test", 'password' => self::PAROLA, 'altcha' => $this->solveAltcha($this->client)];
            $this->client->request('POST', '/api/auth', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
            $this->tokens[$who] = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
        }

        return $this->tokens[$who];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, ?string $who = null, array $files = []): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($who !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($who);
        }
        $this->client->request($method, $uri, files: $files, server: $server);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** Copie temporară: încărcarea nu trebuie să atingă fixture-ul. */
    private function file(string $fixture, string $clientName): UploadedFile
    {
        $path = $this->tmpDir.'/'.bin2hex(random_bytes(6));
        copy(self::FIXTURES.$fixture, $path);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /** @return array<string, mixed> */
    private function upload(string $subject, string $who, string $fixture = 'photo-gps.jpg', string $clientName = 'poza.jpg'): array
    {
        return $this->call('POST', '/api/civic_subjects/'.$this->s[$subject]->getId()->toBase32().'/media', $who, ['file' => $this->file($fixture, $clientName)]);
    }

    private function stored(array $media, string $variant): string
    {
        return $this->mediaDir.'/'.$media['subjectId'].'/'.$media['id'].'-'.$variant.'.webp';
    }

    /** @return array<string, mixed> */
    private function link(string $subject, ?string $who, mixed $url, mixed $label = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($who !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($who);
        }
        $this->client->request('POST', '/api/civic_subjects/'.$this->s[$subject]->getId()->toBase32().'/links', server: $server, content: json_encode(['url' => $url, 'label' => $label]));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testLinkuriValidate(): void
    {
        $l = $this->link('vlad', 'vlad', '  HTTPS://YouTube.com/watch?v=abc#t=1 ', "  Filmare\n de pe teren ");
        self::assertSame(201, $this->code());
        self::assertSame(['LINK', 'PUBLISHED', 'https://youtube.com/watch?v=abc#t=1', 'Filmare de pe teren'], [$l['kind'], $l['status'], $l['url'], $l['label']]);
        self::assertArrayNotHasKey('displayUrl', $l);

        foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://exemplu.ro/f', 'https://user:parola@exemplu.ro', 'https://exemplu.ro/a b', '//exemplu.ro', 'exemplu.ro', '', 42] as $bad) {
            $this->link('vlad', 'vlad', $bad);
            self::assertSame(422, $this->code(), json_encode($bad));
        }
        $this->link('vlad', 'vlad', 'https://youtube.com/watch?v=abc#t=1');
        self::assertSame(422, $this->code(), 'duplicat');
        $this->link('vlad', 'vlad', 'https://exemplu.ro/'.str_repeat('a', 2048));
        self::assertSame(422, $this->code(), 'prea lung');

        $this->link('vlad', 'maria', 'https://exemplu.ro');
        self::assertSame(403, $this->code());
        $this->link('vlad', null, 'https://exemplu.ro');
        self::assertSame(401, $this->code());

        // Linkul nu are fișier de servit.
        $this->call('GET', '/api/media/'.$l['id'].'/display');
        self::assertSame(404, $this->code());
    }

    public function testLinkuriLimitaSeparataDePozeSiModerare(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->link('vlad', 'vlad', "https://exemplu.ro/$i");
            self::assertSame(201, $this->code());
        }
        $this->link('vlad', 'vlad', 'https://exemplu.ro/10');
        self::assertSame(422, $this->code());
        $this->upload('vlad', 'vlad');
        self::assertSame(201, $this->code(), 'pozele au limita lor');

        $l = $this->link('ion', 'ion', 'https://exemplu.ro/ion');
        self::assertSame('PENDING', $l['status']);
        $subjectId = $this->s['ion']->getId()->toBase32();
        self::assertSame([], $this->call('GET', "/api/civic_subjects/$subjectId/media")['items']);
        self::assertSame(['LINK'], array_column($this->call('GET', '/api/moderation/media', 'admin')['items'], 'kind'));
        $this->call('POST', '/api/media/'.$l['id'].'/approve', 'admin');
        self::assertSame(['https://exemplu.ro/ion'], array_column($this->call('GET', "/api/civic_subjects/$subjectId/media")['items'], 'url'));
    }

    public function testIstoriculPublicFaraContinutulCelorScoase(): void
    {
        $poza = $this->upload('vlad', 'vlad');
        $pastrat = $this->link('vlad', 'vlad', 'https://exemplu.ro/pastrat', 'Păstrat');
        $scos = $this->link('vlad', 'vlad', 'https://exemplu.ro/date-personale');
        $this->call('DELETE', '/api/media/'.$scos['id'], 'admin');
        $this->call('DELETE', '/api/media/'.$poza['id'], 'vlad');
        // Al lui ion: scos înainte de aprobare - n-a fost niciodată public.
        $pending = $this->link('ion', 'ion', 'https://exemplu.ro/ion');
        $this->call('DELETE', '/api/media/'.$pending['id'], 'ion');
        self::assertSame(204, $this->code());

        $events = $this->call('GET', '/api/civic_subjects/'.$this->s['vlad']->getId()->toBase32().'/media/history')['events'];
        self::assertSame(200, $this->code());
        self::assertSame(
            [['ADDED', 'PHOTO'], ['ADDED', 'LINK'], ['ADDED', 'LINK'], ['REMOVED'], ['REMOVED']],
            array_map(static fn (array $e): array => $e['event'] === 'ADDED' ? [$e['event'], $e['kind']] : [$e['event']], $events),
        );
        // Scoaterile pot cădea în aceeași secundă: verificăm perechile, nu ordinea.
        $removed = array_column(array_slice($events, 3), 'by', 'kind');
        ksort($removed);
        self::assertSame(['LINK' => 'ADMIN', 'PHOTO' => 'AUTHOR'], $removed);
        $json = json_encode($events);
        self::assertStringNotContainsString('date-personale', $json);
        self::assertStringContainsString('exemplu.ro\/pastrat', $json);
        self::assertSame($pastrat['id'], array_values(array_filter($events, static fn (array $e): bool => isset($e['url'])))[0]['mediaId']);

        self::assertSame([], $this->call('GET', '/api/civic_subjects/'.$this->s['ion']->getId()->toBase32().'/media/history')['events']);
        $this->call('GET', '/api/civic_subjects/'.$this->s['ascuns']->getId()->toBase32().'/media/history');
        self::assertSame(404, $this->code());
    }

    public function testJpegReencodatFaraMetadateSiFaraRevizieNoua(): void
    {
        $revision = $this->s['vlad']->getRevisionNumber();
        $m = $this->upload('vlad', 'vlad');
        self::assertSame(201, $this->code());
        self::assertSame('PUBLISHED', $m['status']);
        // Fixture 80x40 cu Orientation=6: după rotire devine portret.
        self::assertSame([40, 80], [$m['width'], $m['height']]);

        foreach (['display', 'thumb'] as $variant) {
            self::assertFileExists($this->stored($m, $variant));
            $out = new \Imagick($this->stored($m, $variant));
            self::assertSame('WEBP', $out->getImageFormat());
            self::assertSame([], $out->getImageProperties('exif:*'), 'EXIF (inclusiv GPS) eliminat');
            self::assertSame([], $out->getImageProfiles('*', false), 'fără profile EXIF/XMP/ICC');
            self::assertStringNotContainsString('Test Autor', (string) file_get_contents($this->stored($m, $variant)));
        }
        self::assertSame($revision, (int) $this->db->fetchOne('SELECT revision_number FROM civic_subject WHERE id = ?', [$this->s['vlad']->getId()->toRfc4122()]));
    }

    public function testHeicAcceptat(): void
    {
        $m = $this->upload('vlad', 'admin', 'photo.heic', 'IMG_0001.HEIC');
        self::assertSame(201, $this->code());
        self::assertSame([80, 40], [$m['width'], $m['height']]);
        self::assertFileExists($this->stored($m, 'display'));
    }

    public function testTipulSeVerificaDupaContinut(): void
    {
        $fake = $this->tmpDir.'/fals';
        file_put_contents($fake, "<svg xmlns='http://www.w3.org/2000/svg'/>");
        $this->call('POST', '/api/civic_subjects/'.$this->s['vlad']->getId()->toBase32().'/media', 'vlad', ['file' => new UploadedFile($fake, 'poza.jpg', 'image/jpeg', null, true)]);
        self::assertSame(422, $this->code());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM subject_media'));
    }

    public function testPeste10MB(): void
    {
        $big = $this->tmpDir.'/mare';
        $h = fopen($big, 'w');
        ftruncate($h, 10 * 1024 * 1024 + 1);
        fclose($h);
        $this->call('POST', '/api/civic_subjects/'.$this->s['vlad']->getId()->toBase32().'/media', 'vlad', ['file' => new UploadedFile($big, 'mare.jpg', null, null, true)]);
        self::assertSame(413, $this->code());
    }

    public function testCelMult10PeSubiect(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subject = $em->find(CivicSubject::class, $this->s['vlad']->getId());
        $author = $subject->getAuthor();
        for ($i = 0; $i < 10; ++$i) {
            $em->persist(SubjectMedia::photo($subject, $author, MediaStatus::PUBLISHED, $i, 10, 10, 100));
        }
        $em->flush();
        $this->upload('vlad', 'vlad');
        self::assertSame(422, $this->code());

        // O fotografie scoasă nu mai ocupă loc.
        $this->db->executeStatement('UPDATE subject_media SET removed_at = now() WHERE position = 0');
        $this->upload('vlad', 'vlad');
        self::assertSame(201, $this->code());
    }

    public function testDoarAutorulSiAdminii(): void
    {
        $this->upload('vlad', 'maria');
        self::assertSame(403, $this->code());
        $this->call('POST', '/api/civic_subjects/'.$this->s['vlad']->getId()->toBase32().'/media', null, ['file' => $this->file('photo-gps.jpg', 'poza.jpg')]);
        self::assertSame(401, $this->code());
        $this->upload('vlad', 'admin');
        self::assertSame(201, $this->code());
    }

    public function testAutorSubModerareAsteaptaAprobareIndividual(): void
    {
        $m = $this->upload('ion', 'ion');
        self::assertSame(201, $this->code());
        self::assertSame('PENDING', $m['status']);
        $subjectId = $this->s['ion']->getId()->toBase32();

        // Subiectul rămâne public; media nu.
        self::assertSame([], $this->call('GET', "/api/civic_subjects/$subjectId/media")['items']);
        $this->call('GET', $m['displayUrl']);
        self::assertSame(404, $this->code());
        self::assertCount(1, $this->call('GET', "/api/civic_subjects/$subjectId/media", 'ion')['items']);
        $this->call('GET', $m['thumbUrl'], 'ion');
        self::assertSame(200, $this->code());

        $this->call('GET', '/api/moderation/media', 'maria');
        self::assertSame(403, $this->code());
        $queue = $this->call('GET', '/api/moderation/media', 'admin')['items'];
        self::assertSame([$m['id']], array_column($queue, 'id'));
        self::assertSame('Groapa ion', $queue[0]['subjectTitle']);

        $this->call('POST', '/api/media/'.$m['id'].'/approve', 'ion');
        self::assertSame(403, $this->code());
        self::assertSame('PUBLISHED', $this->call('POST', '/api/media/'.$m['id'].'/approve', 'admin')['status']);
        self::assertSame([], $this->call('GET', '/api/moderation/media', 'admin')['items']);

        $this->call('GET', $m['displayUrl']);
        self::assertSame(200, $this->code());
        $r = $this->client->getResponse();
        self::assertSame('/_protected_media/'.$m['subjectId'].'/'.$m['id'].'-display.webp', $r->headers->get('X-Accel-Redirect'));
        self::assertSame('', $r->getContent());
    }

    public function testSubiectAscunsNuIsiExpuneMedia(): void
    {
        $m = $this->upload('ascuns', 'vlad');
        self::assertSame(201, $this->code());
        $subjectId = $this->s['ascuns']->getId()->toBase32();
        $this->call('GET', "/api/civic_subjects/$subjectId/media");
        self::assertSame(404, $this->code());
        $this->call('GET', "/api/civic_subjects/$subjectId/media", 'maria');
        self::assertSame(404, $this->code());
        $this->call('GET', $m['displayUrl'], 'maria');
        self::assertSame(404, $this->code());
        $this->call('GET', $m['displayUrl'], 'admin');
        self::assertSame(200, $this->code());
        $this->call('GET', '/api/media/'.$m['id'].'/original');
        self::assertSame(404, $this->code());
    }

    public function testScoatereaStergeFisierele(): void
    {
        $m = $this->upload('vlad', 'vlad');
        $this->call('DELETE', '/api/media/'.$m['id'], 'maria');
        self::assertSame(403, $this->code());
        $this->call('DELETE', '/api/media/'.$m['id'], 'vlad');
        self::assertSame(204, $this->code());

        self::assertFileDoesNotExist($this->stored($m, 'display'));
        self::assertFileDoesNotExist($this->stored($m, 'thumb'));
        self::assertSame([], $this->call('GET', '/api/civic_subjects/'.$m['subjectId'].'/media')['items']);
        $this->call('GET', $m['displayUrl'], 'vlad');
        self::assertSame(404, $this->code());
        // Rândul rămâne pentru istoricul public (M.2).
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM subject_media WHERE removed_at IS NOT NULL AND removed_by_id IS NOT NULL'));
    }
}
