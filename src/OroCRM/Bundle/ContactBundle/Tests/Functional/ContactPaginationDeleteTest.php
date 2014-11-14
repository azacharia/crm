<?php

namespace OroCRM\Bundle\ContactBundle\Tests\Functional;

use OroCRM\Bundle\ContactBundle\Tests\Functional\DataFixtures\LoadContactEntitiesData;
use OroCRM\Bundle\ContactBundle\Entity\Contact;

/**
 * @outputBuffering enabled
 * @dbIsolation
 */
class ContactPaginationDeleteTest extends AbstractContactPaginationTestCase
{
    public function testViewDeletedEntity()
    {
        $this->client->followRedirects(true);

        // open first contact to generate entity pagination set
        $crawler = $this->openEntity(
            'orocrm_contact_view',
            LoadContactEntitiesData::FIRST_ENTITY_NAME,
            $this->gridParams
        );

        // remove second contact from DB
        $em = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        $contact = $this->getContainer()->get('doctrine')
            ->getRepository('OroCRMContactBundle:Contact')
            ->findOneBy(['firstName' => LoadContactEntitiesData::SECOND_ENTITY_NAME]);
        $em->remove($contact);
        $em->flush();

        // click next link
        $next = $crawler->filter('#entity-pagination a .icon-chevron-right')->parents()->link();
        $crawler = $this->client->click($next);

        $this->assertCurrentContactName($crawler, LoadContactEntitiesData::THIRD_ENTITY_NAME);
        $this->assertPositionEntity($crawler, 2, 3);
        $this->assertContains(
            "Some of the records are no longer available. You are now viewing 3 records.",
            $crawler->html()
        );
    }
}