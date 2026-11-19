<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Login\Ldap\LdapEntry;
use Exceedone\Exment\Services\Login\Ldap\LdapService;
use Exceedone\Exment\Services\Login\Ldap\LdapUser;

/**
 * LDAP user entry and the ${...} mapping keys of the login setting (UP-03).
 *
 * LDAP moved from adldap2 (cannot be installed with Laravel 12) to LdapRecord. The keys must
 * read the same values as the adldap2 user model did: the expected values below were taken
 * from adldap2 v10 against the same entries. No LDAP library or server is needed here.
 */
class LdapEntryTest extends UnitTestBase
{
    /**
     * One entry in the raw ldap_get_entries() format LdapRecord returns.
     *
     * @return array<mixed>
     */
    protected function rawEntry(): array
    {
        return [
            'objectclass' => ['count' => 2, 0 => 'inetOrgPerson', 1 => 'person'],
            0 => 'objectclass',
            'cn' => ['count' => 1, 0 => 'Albert Einstein'],
            1 => 'cn',
            'sn' => ['count' => 1, 0 => 'Einstein'],
            2 => 'sn',
            'givenName' => ['count' => 1, 0 => 'Albert'],
            3 => 'givenName',
            'uid' => ['count' => 1, 0 => 'einstein'],
            4 => 'uid',
            'mail' => ['count' => 1, 0 => 'einstein@example.com'],
            5 => 'mail',
            'seealso' => ['count' => 0],
            6 => 'seealso',
            'count' => 7,
            'dn' => 'uid=einstein,dc=example,dc=com',
        ];
    }

    public function testRawEntryIsNormalized(): void
    {
        $entry = new LdapEntry($this->rawEntry(), LdapService::SCHEMA_OPEN_LDAP);

        $this->assertSame('uid=einstein,dc=example,dc=com', $entry->getDn());
        $this->assertSame(['inetOrgPerson', 'person'], $entry->getAttributes()['objectclass']);
        $this->assertSame(['Albert'], $entry->getAttributes()['givenname'], 'attribute names are lower-cased');
        $this->assertSame(['uid=einstein,dc=example,dc=com'], $entry->getAttributes()['dn']);
        $this->assertArrayNotHasKey('count', $entry->getAttributes());
        $this->assertArrayNotHasKey(0, $entry->getAttributes());
        $this->assertSame('einstein', $entry->getFirstAttribute('UID'));
    }

    /**
     * Attribute keys, adldap2 getter keys and missing keys resolve like adldap2 did.
     */
    public function testResolveLikeAdldap(): void
    {
        $entry = new LdapEntry($this->rawEntry(), LdapService::SCHEMA_OPEN_LDAP);

        // attributes
        $this->assertSame([true, 'einstein'], $entry->resolve('uid'));
        $this->assertSame([true, 'einstein@example.com'], $entry->resolve('MAIL'));
        // adldap2 getters: getEmail() = mail, getFirstName() = givenname, getLastName() = sn, getCommonName() = cn
        $this->assertSame([true, 'einstein@example.com'], $entry->resolve('email'));
        $this->assertSame([true, 'Albert'], $entry->resolve('firstname'));
        $this->assertSame([true, 'Einstein'], $entry->resolve('lastname'));
        $this->assertSame([true, 'Albert Einstein'], $entry->resolve('commonname'));
        $this->assertSame([true, 'uid=einstein,dc=example,dc=com'], $entry->resolve('dn'));
        // a getter whose attribute is missing still "has" a value (method_exists() before)
        $this->assertSame([true, null], $entry->resolve('title'));
        // description is an adldap2 getter (getDescription()): it "has" a value even when missing
        $this->assertSame([true, null], $entry->resolve('description'));
        // no attribute, no getter / empty attribute
        $this->assertSame([false, null], $entry->resolve('notexist'));
        $this->assertSame([false, null], $entry->resolve('seealso'));
    }

    /**
     * The getters reading a schema-specific attribute.
     */
    public function testSchemaSpecificGetters(): void
    {
        $raw = $this->rawEntry() + [
            'samaccountname' => ['count' => 1, 0 => 'aeinstein'],
            'distinguishedname' => ['count' => 1, 0 => 'CN=Albert Einstein,DC=corp'],
        ];
        $openLdap = new LdapEntry($raw, LdapService::SCHEMA_OPEN_LDAP);
        $ad = new LdapEntry($raw, LdapService::SCHEMA_ACTIVE_DIRECTORY);

        $this->assertSame([true, 'einstein'], $openLdap->resolve('accountname'));
        $this->assertSame([true, 'aeinstein'], $ad->resolve('accountname'));
        $this->assertSame([true, 'uid=einstein,dc=example,dc=com'], $openLdap->resolve('distinguishedname'));
        $this->assertSame([true, 'CN=Albert Einstein,DC=corp'], $ad->resolve('distinguishedname'));
    }

    /**
     * Active Directory objectGUID is converted exactly as adldap2 did (values from adldap2 Guid).
     */
    public function testActiveDirectoryGuidLikeAdldap(): void
    {
        $vectors = [
            'e3a0c5b26a4c4d47a3f0b1c2d3e4f501' => 'b2c5a0e3-4c6a-474d-a3f0-b1c2d3e4f501',
            '00112233445566778899aabbccddeeff' => '33221100-5544-7766-8899-aabbccddeeff',
        ];
        foreach ($vectors as $hex => $expected) {
            $entry = new LdapEntry(['objectguid' => ['count' => 1, 0 => hex2bin($hex)], 'dn' => 'CN=x'], LdapService::SCHEMA_ACTIVE_DIRECTORY);
            $this->assertSame($expected, $entry->getAuthIdentifier());
            $this->assertSame([true, $expected], $entry->resolve('convertedguid'));
        }

        $this->assertNull((new LdapEntry(['dn' => 'CN=x'], LdapService::SCHEMA_ACTIVE_DIRECTORY))->getAuthIdentifier());
    }

    /**
     * OpenLDAP: the id is entryUUID when the server returns it, null otherwise (as adldap2 with "*").
     */
    public function testOpenLdapIdentifier(): void
    {
        $this->assertNull((new LdapEntry($this->rawEntry(), LdapService::SCHEMA_OPEN_LDAP))->getAuthIdentifier());

        $raw = $this->rawEntry() + ['entryuuid' => ['count' => 1, 0 => '29f6dc28-2f64-1033-898b-a53eb149a944']];
        $this->assertSame('29f6dc28-2f64-1033-898b-a53eb149a944', (new LdapEntry($raw, LdapService::SCHEMA_OPEN_LDAP))->getAuthIdentifier());
    }

    /**
     * Mapping strings of the login setting ("${key}" combinations), values from adldap2 on the same entry.
     */
    public function testMappingValueLikeAdldap(): void
    {
        $entry = new LdapEntry($this->rawEntry(), LdapService::SCHEMA_OPEN_LDAP);

        $this->assertSame('Albert Einstein (einstein)', $this->map($entry, '${cn} (${uid})'));
        $this->assertSame('Einstein Albert', $this->map($entry, '${lastname} ${firstname}'));
        $this->assertSame('xeinstein@example.comy', $this->map($entry, 'x${email}y'));
        $this->assertSame('einstein', $this->map($entry, 'uid'));
        $this->assertSame(' einstein@example.com', $this->map($entry, '${notexist} ${mail}'));
        $this->assertNull($this->map($entry, '${notexist}'));
        $this->assertNull($this->map($entry, 'notexist'));
    }

    /**
     * Call LdapUser::getMappingItemValue() as CustomLoginUserBase::setMappingValue() does.
     *
     * @return mixed
     */
    protected function map(LdapEntry $entry, string $mappingKey)
    {
        $replaceMaps = [];
        preg_match_all('/\${(?<key>.+?)}/', $mappingKey, $output);
        if (count($output['key']) > 0) {
            foreach ($output['key'] as $index => $key) {
                $replaceMaps[$key] = $output[0][$index];
            }
        } else {
            $replaceMaps[$mappingKey] = $mappingKey;
        }

        return (new \ReflectionMethod(LdapUser::class, 'getMappingItemValue'))->invoke(null, $entry, $mappingKey, $replaceMaps);
    }
}
