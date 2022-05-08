<?php declare(strict_types=1);

namespace App\Tests\Controller\Entry;

use App\Repository\EntryCommentRepository;
use App\Repository\EntryRepository;
use App\Tests\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class EntryDeleteControllerTest extends WebTestCase
{
    public function testAuthorCanDeleteEntry()
    {
        $client = $this->createClient();
        $client->loginUser($user = $this->getUserByUsername('regularUser'));

        $user1 = $this->getUserByUsername('regularUser');
        $user2 = $this->getUserByUsername('regularUser2');

        $entry = $this->getEntryByTitle('przykladowa tresc', null, 'przykładowa treść wpisu');
        $this->getEntryByTitle('test1');
        $this->getEntryByTitle('test2');

        $comment1 = $this->createEntryComment('test', $entry);
        $comment2 = $this->createEntryComment('test2', $entry, $user2, $comment1);

        $this->createVote(1, $entry, $user2);
        $this->createVote(1, $comment1, $user2);
        $this->createVote(1, $comment2, $user2);
        $this->createVote(1, $comment2, $user1);

        $crawler = $client->request('GET', "/m/polityka/t/{$entry->getId()}/-/edytuj");

        $client->submit(
            $crawler->selectButton('usuń')->form()
        );

        $this->assertResponseRedirects("/m/polityka");

        $crawler = $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('.kbin-entry-title', 'przykladowa tresc');
        $this->assertSelectorTextContains('.kbin-sidebar .kbin-magazine .kbin-magazine-stats-links', 'Treści 2');

        $repository = static::getContainer()->get(EntryRepository::class);
        $this->assertSame(3, $repository->count([]));

        $repository = static::getContainer()->get(EntryCommentRepository::class);
        $this->assertSame(2, $repository->count([]));
    }

    public function testUnauthorizedUserCannotEditOrPurgeEntry()
    {
        $this->expectException(AccessDeniedException::class);

        $client = $this->createClient();
        $client->loginUser($user = $this->getUserByUsername('secondUser'));
        $client->catchExceptions(false);

        $entry = $this->getEntryByTitle('przykładowy wpis');

        $crawler = $client->request('GET', "/m/polityka/t/{$entry->getId()}/-/komentarze");

        $this->assertEmpty($crawler->filter('.kbin-entry-meta')->selectLink('edytuj'));

        $crawler = $client->request('GET', "/m/polityka/t/{$entry->getId()}/-/edytuj");

        $this->assertTrue($client->getResponse()->isForbidden());
    }

    public function testAdminUserCanPurgeEntry()
    {
        $client = $this->createClient();
        $client->loginUser($user = $this->getUserByUsername('regularUser'));

        $entry    = $this->createEntry('entry example', $this->getMagazineByName('polityka'), $user);
        $comment1 = $this->createEntryComment('comment', $entry, $user);

        $comment2 = $this->createEntryComment('comment2', $entry, $this->getUserByUsername('regularUser2'));
        $this->createEntryComment('comment3', $entry, $this->getUserByUsername('regularUser3'), $comment1);

        $this->createVote(1, $entry, $this->getUserByUsername('regularUser2'));
        $this->createVote(1, $comment1, $this->getUserByUsername('regularUser2'));
        $this->createVote(1, $comment2, $this->getUserByUsername('regularUser3'));

        $client->loginUser($admin = $this->getUserByUsername('admin', true));

        $crawler = $client->request('GET', "/m/polityka/t/{$entry->getId()}");

        $client->submit($crawler->filter('.kbin-entry-main')->selectButton('wyczyść')->form());

        $repository = static::getContainer()->get(EntryRepository::class);
        $this->assertSame(0, $repository->count([]));

        $repository = static::getContainer()->get(EntryCommentRepository::class);
        $this->assertSame(0, $repository->count([]));
    }

    public function testModeratorCanRestoreEntry()
    {
        $client = $this->createClient();
        $client->loginUser($moderator = $this->getUserByUsername('moderator'));

        $this->getMagazineByName('polityka', $moderator);

        $entry = $this->createEntry('entry example', $this->getMagazineByName('polityka'), $this->getUserByUsername('regular'));
        $this->createEntryComment('comment', $entry, $moderator);

        $crawler = $client->request('GET', "/");

        $client->submit(
            $crawler->selectButton('usuń')->form()
        );

        $crawler = $client->followRedirect();

        $this->assertCount(0, $crawler->filter('article'));

        $crawler = $client->click($crawler->filter('.kbin-sidebar')->selectLink('Kosz')->link());

        $this->assertCount(1, $crawler->filter('article'));

        $client->submit(
            $crawler->selectButton('przywróć')->form()
        );

        $crawler = $client->followRedirect();

        $this->assertCount(1, $crawler->filter('article'));

        $crawler = $client->click($crawler->filter('.kbin-sidebar')->selectLink('Kosz')->link());

        $this->assertCount(0, $crawler->filter('article'));
    }
}
