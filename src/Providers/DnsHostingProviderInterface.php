<?php

declare(strict_types=1);

namespace PlexDNS\Providers;

/**
 * Contract for DNS hosting providers used by PlexDNS.
 *
 * Implementations SHOULD:
 *  - Throw exceptions on transport/provider failures (do not silently return null/false).
 *  - Normalise record formats where possible.
 */
interface DnsHostingProviderInterface {
    /**
     * Create a new DNS zone / domain at the provider.
     *
     * @param string $domainName Fully qualified domain name of the zone.
     * @return mixed Provider-specific response payload.
     */
    public function createDomain(string $domainName);

    /**
     * List all domains / zones visible to the current API credentials.
     *
     * @return array<mixed> List of domains / zones.
     */
    public function listDomains();

    /**
     * Get metadata/details for a single domain / zone.
     *
     * @param string $domainName
     * @return array<mixed> Domain details.
     */
    public function getDomain(string $domainName);

    /**
     * Resolve which zone is authoritative for the given FQDN.
     *
     * @param string $qname Fully qualified domain name.
     * @return string|null Authoritative zone name or null if none found.
     */
    public function getResponsibleDomain(string $qname);

    /**
     * Export a domain / zone as a standard zonefile.
     *
     * @param string $domainName
     * @return string Zonefile content.
     */
    public function exportDomainAsZonefile(string $domainName);

    /**
     * Delete a DNS zone / domain from the provider.
     *
     * @param string $domainName
     * @return bool True on success.
     */
    public function deleteDomain(string $domainName);

    /**
     * Create a single RRset within a zone.
     *
     * @param string $domainName
     * @param array<string,mixed> $rrsetData
     * @return mixed Provider-specific response or identifier.
     */
    public function createRRset(string $domainName, array $rrsetData);

    /**
     * Create multiple RRsets in bulk.
     *
     * @param string $domainName
     * @param array<int,array<string,mixed>> $rrsetDataArray
     * @return mixed Provider-specific response.
     */
    public function createBulkRRsets(string $domainName, array $rrsetDataArray);

    /**
     * Retrieve all RRsets for a zone.
     *
     * @param string $domainName
     * @return array<mixed> List of records.
     */
    public function retrieveAllRRsets(string $domainName);

    /**
     * Retrieve a specific RRset by name and type.
     *
     * @param string $domainName
     * @param string $subname Relative name (e.g. '@', 'www').
     * @param string $type    RR type (A, AAAA, MX, etc.).
     * @return array<mixed> Matching RRset data.
     */
    public function retrieveSpecificRRset(string $domainName, string $subname, string $type);

    /**
     * Modify a single RRset.
     *
     * @param string $domainName
     * @param string $subname
     * @param string $type
     * @param array<string,mixed> $rrsetData
     * @return mixed Provider-specific response.
     */
    public function modifyRRset(string $domainName, string $subname, string $type, array $rrsetData);

    /**
     * Modify multiple RRsets in bulk.
     *
     * @param string $domainName
     * @param array<int,array<string,mixed>> $rrsetDataArray
     * @return mixed Provider-specific response.
     */
    public function modifyBulkRRsets(string $domainName, array $rrsetDataArray);

    /**
     * Delete a specific RRset value.
     *
     * @param string $domainName
     * @param string $subname
     * @param string $type
     * @param string $value  Record content/value to match.
     * @return bool True on success.
     */
    public function deleteRRset(string $domainName, string $subname, string $type, string $value);

    /**
     * Delete multiple RRsets in bulk.
     *
     * @param string $domainName
     * @param array<int,array<string,mixed>> $rrsetDataArray
     * @return mixed Provider-specific response.
     */
    public function deleteBulkRRsets(string $domainName, array $rrsetDataArray);

    /**
     * Enable DNSSEC for the given domain.
     *
     * Implementations SHOULD:
     *  - Create keys if needed.
     *  - Enable DNSSEC signing on the zone.
     *
     * @param string $domainName
     * @return array<mixed> Provider-specific status / key info.
     */
    public function enableDNSSEC(string $domainName);

    /**
     * Disable DNSSEC for the given domain.
     *
     * Implementations SHOULD:
     *  - Stop signing the zone.
     *  - Remove / deactivate keys as per provider behaviour.
     *
     * @param string $domainName
     * @return bool True on success.
     */
    public function disableDNSSEC(string $domainName);

    /**
     * Get current DNSSEC status and key/DS information.
     *
     * Suggested standard fields:
     *  - enabled: bool
     *  - ds: array<int,array<string,mixed>>   DS records (if any)
     *  - keys: array<int,array<string,mixed>> Key info (if available)
     *  - raw: mixed                           Original provider payload
     *
     * @param string $domainName
     * @return array<mixed>
     */
    public function getDNSSECStatus(string $domainName);

    /**
     * Get DS records suitable for publishing at the registry.
     *
     * Each DS entry SHOULD at least contain:
     *  - key_tag
     *  - algorithm
     *  - digest_type
     *  - digest
     *
     * @param string $domainName
     * @return array<int,array<string,mixed>>
     */
    public function getDSRecords(string $domainName);
}