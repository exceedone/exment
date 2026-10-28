<?php

namespace Exceedone\Exment\Services\Login\Ldap;

/**
 * A user entry read from the LDAP server (raw ldap_get_entries() format, as LdapRecord returns it).
 *
 * The mapping keys of the login setting (${mail}, ${email}...) are resolved like the adldap2
 * user model Exment used up to v6 did: a key naming one of its getters (email, firstname,
 * accountname...) reads the attribute behind that getter for the schema, any other key
 * reads the attribute of that name.
 */
class LdapEntry
{
    /**
     * Getter name of the adldap2 user model => attribute, same for every schema.
     * Generated from adldap2 v10 Models\User + Schemas (getFirstAttribute($this->schema->xxx())).
     */
    protected const GETTERS = [
        'accountexpiry' => 'accountexpires',
        'accounttype' => 'samaccounttype',
        'badpasswordcount' => 'badpwdcount',
        'badpasswordtime' => 'badpasswordtime',
        'commonname' => 'cn',
        'company' => 'company',
        'country' => 'c',
        'createdat' => 'whencreated',
        'department' => 'department',
        'departmentnumber' => 'departmentnumber',
        'description' => 'description',
        'displayname' => 'displayname',
        'dn' => 'dn',
        'email' => 'mail',
        'employeeid' => 'employeeid',
        'employeenumber' => 'employeenumber',
        'employeetype' => 'employeetype',
        'facsimilenumber' => 'facsimiletelephonenumber',
        'firstname' => 'givenname',
        'homedirectory' => 'homedirectory',
        'homedrive' => 'homedrive',
        'homemdb' => 'homemdb',
        'homephone' => 'homephone',
        'info' => 'info',
        'initials' => 'initials',
        'instancetype' => 'instancetype',
        'ipphone' => 'ipphone',
        'jpegphoto' => 'jpegphoto',
        'lastlogoff' => 'lastlogoff',
        'lastlogon' => 'lastlogon',
        'lastlogontimestamp' => 'lastlogontimestamp',
        'lastname' => 'sn',
        'legacyexchangedn' => 'legacyexchangedn',
        'locale' => 'l',
        'mailnickname' => 'mailnickname',
        'managedby' => 'managedby',
        'manager' => 'manager',
        'maxpasswordage' => 'maxpwdage',
        'mobilenumber' => 'mobile',
        'name' => 'name',
        'objectsid' => 'objectsid',
        'othermobilenumber' => 'othermobile',
        'passwordlastset' => 'pwdlastset',
        'personaltitle' => 'personaltitle',
        'physicaldeliveryofficename' => 'physicaldeliveryofficename',
        'postalcode' => 'postalcode',
        'postofficebox' => 'postofficebox',
        'primarygroupid' => 'primarygroupid',
        'profilepath' => 'profilepath',
        'roomnumber' => 'roomnumber',
        'scriptpath' => 'scriptpath',
        'streetaddress' => 'streetaddress',
        'telephonenumber' => 'telephonenumber',
        'thumbnail' => 'thumbnailphoto',
        'title' => 'title',
        'updatedat' => 'whenchanged',
        'useraccountcontrol' => 'useraccountcontrol',
        'userprincipalname' => 'userprincipalname',
        'userworkstations' => 'userworkstations',
    ];

    /**
     * Getter name => attribute, depending on the schema.
     */
    protected const SCHEMA_GETTERS = [
        LdapService::SCHEMA_ACTIVE_DIRECTORY => [
            'accountname' => 'samaccountname',
            'distinguishedname' => 'distinguishedname',
            'lockouttime' => 'lockouttime',
            'objectcategorydn' => 'objectcategory',
            'objectguid' => 'objectguid',
        ],
        LdapService::SCHEMA_OPEN_LDAP => [
            'accountname' => 'uid',
            'distinguishedname' => 'dn',
            'lockouttime' => 'pwdaccountlockedtime',
            'objectcategorydn' => 'objectclass',
            'objectguid' => 'entryuuid',
        ],
    ];

    /**
     * @var string
     */
    protected $dn;

    /**
     * @var array<string, array<mixed>> lower-case attribute name => values
     */
    protected $attributes = [];

    /**
     * @var string
     */
    protected $schema;

    /**
     * @param array<mixed> $entry one entry of ldap_get_entries()
     * @param string $schema LdapService::SCHEMA_*
     */
    public function __construct(array $entry, string $schema)
    {
        $this->schema = $schema;
        $this->dn = is_array($entry['dn'] ?? null) ? (string) ($entry['dn'][0] ?? '') : (string) ($entry['dn'] ?? '');

        foreach ($entry as $key => $values) {
            if (!is_string($key) || $key === 'count' || $key === 'dn') {
                continue;
            }
            if (is_array($values)) {
                unset($values['count']);
                $values = array_values($values);
            } else {
                $values = [$values];
            }
            $this->attributes[strtolower($key)] = $values;
        }
        // the dn reads as an attribute as well, as it did with adldap2
        $this->attributes['dn'] = [$this->dn];
    }

    public function getDn(): string
    {
        return $this->dn;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * The first value of an attribute.
     *
     * @return mixed
     */
    public function getFirstAttribute(string $name)
    {
        return $this->attributes[strtolower($name)][0] ?? null;
    }

    /**
     * Unique id of the user: the GUID (objectGUID of Active Directory, entryUUID of OpenLDAP).
     */
    public function getAuthIdentifier(): ?string
    {
        if ($this->schema === LdapService::SCHEMA_ACTIVE_DIRECTORY) {
            $guid = $this->getFirstAttribute('objectguid');
            return is_string($guid) && strlen($guid) === 16 ? static::convertGuid($guid) : null;
        }

        $uuid = $this->getFirstAttribute('entryuuid');
        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * Resolve a mapping key as the adldap2 user model did.
     *
     * @return array{0: bool, 1: mixed} [whether the key has a value, the value]
     *     A key naming a getter always "has" a value (it may be null), like method_exists() did.
     */
    public function resolve(string $key): array
    {
        $key = strtolower($key);

        if ($key === 'convertedguid') {
            return [true, $this->getAuthIdentifier()];
        }

        $getter = static::SCHEMA_GETTERS[$this->schema][$key] ?? static::GETTERS[$key] ?? null;
        if (isset($getter)) {
            return [true, $this->getFirstAttribute($getter)];
        }

        if (!array_key_exists($key, $this->attributes) || count($this->attributes[$key]) === 0) {
            return [false, null];
        }

        return [true, $this->attributes[$key][0]];
    }

    /**
     * Binary objectGUID to its string form (as adldap2 Utilities::binaryGuidToString()).
     */
    protected static function convertGuid(string $binary): string
    {
        $hex = bin2hex($binary);

        $parts = [
            substr($hex, 6, 2) . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2),
            substr($hex, 10, 2) . substr($hex, 8, 2),
            substr($hex, 14, 2) . substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ];

        return implode('-', $parts);
    }
}
