<?php
declare(strict_types=1);

/**
 * @see \sprintf()
 */
function writeln(string $format, ...$values): void
{
    fwrite(STDOUT, sprintf($format, ...$values) . PHP_EOL);
}

final class Env
{
    private static ?self $instance = null;

    /**
     * @var array<string,int>
     */
    private array $skipUpdate;

    /**
     * @var array<string,bool>
     */
    private array $semanticallyVersionedRepos = [
        'library/composer'      => true,
        'phpmyadmin/phpmyadmin' => true,
    ];

    private bool $updateDockerfile;

    private bool $updateDockerCompose;

    private function __construct()
    {
        $this->skipUpdate = array_fill_keys(preg_split('/\s*,+\s*/', mb_strtolower(trim(getenv('INPUT_SKIP-UPDATE') ?: ''))), true);

        if (false === preg_match_all('/([^\s,:]+)(?:\s*:\s*([^\s,]+))?/m', trim(getenv('INPUT_SEMANTICALLY-VERSIONED-REPOS') ?: ''), $matches, PREG_SET_ORDER)) {
            throw new RuntimeException(sprintf('input.semantically-versioned-repos: %s', preg_last_error_msg()));
        }

        foreach ($matches as $match) {
            $this->semanticallyVersionedRepos[$match[1]] = filter_var($match[2] ?? 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        $this->updateDockerfile = (filter_var(getenv('INPUT_UPDATE-DOCKERFILE') ?: 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false) &&
            is_file(self::getDockerfilePath());

        $this->updateDockerCompose = (filter_var(getenv('INPUT_UPDATE-DOCKER-COMPOSE') ?: 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false) &&
            is_file(self::getDockerComposeFilePath());
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function getDockerfilePath(): string
    {
        return getcwd() . DIRECTORY_SEPARATOR . 'Dockerfile';
    }

    public static function getDockerComposeFilePath(): string
    {
        return getcwd() . DIRECTORY_SEPARATOR . 'docker-compose.yml';
    }

    public function isSkippedForUpdate(string $repoName): bool
    {
        return $this->skipUpdate[$repoName] ?? false;
    }

    public function isSemanticallyVersioned(string $qualifiedRepoName): bool
    {
        return $this->semanticallyVersionedRepos[$qualifiedRepoName] ?? false;
    }

    public function shouldUpdateDockerfile(): bool
    {
        return $this->updateDockerfile;
    }

    public function shouldUpdateDockerCompose(): bool
    {
        return $this->updateDockerCompose;
    }
}

final class DockerHub
{
    public const REQUEST_STRATEGY_CURL = 'cUrlRequest';

    public const REQUEST_STRATEGY_FOPEN = 'fOpenRequest';

    private const REPO_AUTH_URL_PATTERN = 'https://auth.docker.io/token?service=registry.docker.io&scope=repository:%s:pull';

    private const REPO_TAGS_LIST_PATTERN = 'https://index.docker.io/v2/%s/tags/list';

    /**
     * @var callable-string
     */
    private static string $strategy = 'cUrlRequest';

    /**
     * @var array<string,array>
     */
    private static array $responseCache = [];

    public static function setRequestStrategy(
        #[\JetBrains\PhpStorm\ExpectedValues(values: [self::REQUEST_STRATEGY_CURL, self::REQUEST_STRATEGY_FOPEN])]
        string $strategy,
    ): void {
        self::$strategy = $strategy;
    }

    #[\JetBrains\PhpStorm\ArrayShape([
        'token' => 'string',
    ])]
    public static function auth(
        string $qualifiedRepoName,
    ): array {
        if (isset(self::$responseCache[__METHOD__][$qualifiedRepoName])) {
            return self::$responseCache[__METHOD__][$qualifiedRepoName];
        }

        $authResponse = self::doRequest(sprintf(self::REPO_AUTH_URL_PATTERN, $qualifiedRepoName));
        $authResponseArray = json_decode($authResponse, true, flags: JSON_THROW_ON_ERROR);

        if (!isset($authResponseArray['token'])) {
            throw new UnexpectedValueException(sprintf(
                '[%s] Could not find token in Docker auth response.%s%s',
                $qualifiedRepoName,
                PHP_EOL,
                $authResponse,
            ));
        }

        return self::$responseCache[__METHOD__][$qualifiedRepoName] = $authResponseArray;
    }

    #[\JetBrains\PhpStorm\ArrayShape([
        'tags' => 'array',
    ])]
    public static function tags(
        string $qualifiedRepoName,
        string $token,
    ): array {
        $cacheKey = md5(sprintf("%s\0%s", $qualifiedRepoName, $token));

        if (isset(self::$responseCache[__METHOD__][$cacheKey])) {
            return self::$responseCache[__METHOD__][$cacheKey];
        }

        $tagsResponse = self::doRequest(sprintf(self::REPO_TAGS_LIST_PATTERN, $qualifiedRepoName), [
            'Authorization' => sprintf('Bearer %s', $token),
        ]);
        $tagsResponseArray = json_decode($tagsResponse, true, flags: JSON_THROW_ON_ERROR);

        if (!isset($tagsResponseArray['tags'])) {
            throw new UnexpectedValueException(sprintf(
                '[%s] Could not find tags list in Docker tags response.%s%s',
                $qualifiedRepoName,
                PHP_EOL,
                $tagsResponse,
            ));
        }

        return self::$responseCache[__METHOD__][$cacheKey] = $tagsResponseArray;
    }

    private static function doRequest(string $url, array $headers = []): string
    {
        return forward_static_call([self::class, self::$strategy], $url, $headers);
    }

    private static function cUrlRequest(string $url, array $headers = []): string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, iterator_to_array(self::prepareHeaders(array_merge(
            ['Content-Type' => 'application/json;charset=UTF-8'],
            $headers,
        ))));

        $data = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new RuntimeException(sprintf('cURL error: %s', curl_error($ch)));
        }

        curl_close($ch);

        return $data;
    }

    private static function fOpenRequest(string $url, array $headers = []): string
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", iterator_to_array(self::prepareHeaders(array_merge(
                    ['Content-Type' => 'application/json;charset=UTF-8'],
                    $headers,
                )))),
            ],
        ];

        $context = stream_context_create($opts);

        $fp = fopen($url, 'r', false, $context);

        try {
            $contents = '';

            while (!feof($fp)) {
                $chunk = fread($fp, 4096);

                if (false === $chunk) {
                    throw new RuntimeException('fread failed to read contents');
                }

                $contents .= $chunk;
            }

            return $contents;
        } finally {
            fclose($fp);
        }
    }

    private static function prepareHeaders(array $headers): Generator
    {
        foreach ($headers as $header => $values) {
            foreach ((array) $values as $value) {
                yield sprintf('%s: %s', $header, $value);
            }
        }
    }
}

final class DockerfileParametersCollection
{
    /**
     * @var array<string,array<positive-int,mixed[]>>
     */
    private array $parameters = [];

    /**
     * @var array<string,mixed[]>
     */
    private array $parameterHashMap = [];

    public function __construct(string $contents)
    {
        preg_match_all('/^ARG\s+(.+?)(?:\s*=\s*|\s+)(.*)$/m', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as [[, $lineOffset], [$tag], [$value, $valueOffset]]) {
            $this->parameters[$tag][$lineOffset] = [
                'name'        => $tag,
                'value'       => $value,
                'valueOffset' => $valueOffset,
                'valueLength' => \strlen($value),
            ];

            $this->parameterHashMap[$this::calculateParameterHash($tag, $valueOffset)] = &$this->parameters[$tag][$lineOffset];
        }
    }

    public static function calculateParameterHash(string $name, int $upToOffset): string
    {
        return hash('crc32b', sprintf("%s\0%d", $name, $upToOffset));
    }

    public function has(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    /**
     * @throws \OutOfBoundsException When argument is unknown
     */
    #[\JetBrains\PhpStorm\ArrayShape([
        'name'        => 'string',
        'value'       => 'string',
        'valueOffset' => 'int',
        'valueLength' => 'int',
    ])]
    public function get(
        string $name,
        int $upToOffset,
    ): array {
        if (!$this->has($name)) {
            throw new OutOfBoundsException(sprintf('Argument "%s" is unknown.', $name));
        }

        $lastIndex = null;
        $offset = null;

        foreach ($this->parameters[$name] as $offset => $_) {
            if ($offset > $upToOffset) {
                break;
            }

            $lastIndex = $offset;
        }

        $lastIndex ??= $offset;

        if (null === $lastIndex) {
            throw new OutOfBoundsException(sprintf('Argument "%s" is unknown.', $name));
        }

        return $this->parameters[$name][$lastIndex];
    }

    public function set(string $name, string $value, int $upToOffset): void
    {
        if (!$this->has($name)) {
            return;
        }

        $lastIndex = null;
        $offset = null;

        foreach ($this->parameters[$name] as $offset => $_) {
            if ($offset > $upToOffset) {
                break;
            }

            $lastIndex = $offset;
        }

        $lastIndex ??= $offset;

        if (null === $lastIndex) {
            return;
        }

        $this->parameters[$name][$lastIndex]['value'] = $value;
    }

    public function setByHash(string $hash, string $value): void
    {
        if (!isset($this->parameterHashMap[$hash])) {
            return;
        }

        $this->parameterHashMap[$hash]['value'] = $value;
    }

    /**
     * @throws \OutOfBoundsException When argument hash is unknown
     */
    #[\JetBrains\PhpStorm\ArrayShape([
        'name'        => 'string',
        'value'       => 'string',
        'valueOffset' => 'int',
        'valueLength' => 'int',
    ])]
    public function getByHash(
        string $hash,
    ): array {
        return $this->parameterHashMap[$hash]
            ?? throw new OutOfBoundsException(sprintf('Argument hash "%s" is unknown.', $hash));
    }

    public function updateOffsets(int $fromOffset, int $offsetShift): void
    {
        if (0 === $offsetShift) {
            return;
        }

        foreach ($this->parameters as $tag => $parameter) {
            foreach ($parameter as $lineOffset => $value) {
                if ($lineOffset >= $fromOffset) {
                    $this->parameters[$tag][$lineOffset] = [
                            'valueOffset' => $value['valueOffset'] + $offsetShift,
                        ] + $value;
                }
            }
        }
    }

    public function applyChanges(string $contents): string
    {
        $parametersChanges = new class() extends SplHeap {
            protected function compare($value1, $value2): int
            {
                return $value1['valueOffset'] <=> $value2['valueOffset'];
            }
        };

        foreach ($this->parameters as $offsets) {
            foreach ($offsets as $value) {
                $parametersChanges->insert($value);
            }
        }

        foreach ($parametersChanges as ['valueOffset' => $valueOffset, 'valueLength' => $valueLength, 'value' => $value]) {
            $contents = substr_replace($contents, $value, $valueOffset, $valueLength);
        }

        return $contents;
    }
}

if (\extension_loaded('curl')) {
    DockerHub::setRequestStrategy(DockerHub::REQUEST_STRATEGY_CURL);
} elseif (ini_get('allow_url_fopen')) {
    DockerHub::setRequestStrategy(DockerHub::REQUEST_STRATEGY_FOPEN);
} else {
    fwrite(STDERR, 'cURL and URL fopen are not available');

    exit(1);
}

try {
    // Dockerfile changes
    $dockerfileChanges = [];

    if (Env::getInstance()->shouldUpdateDockerfile()) {
        $dockerfilePath = Env::getDockerfilePath();
        $dockerfileContents = file_get_contents($dockerfilePath);
        $dockerfileArguments = new DockerfileParametersCollection($dockerfileContents);

        file_put_contents($dockerfilePath, $dockerfileArguments->applyChanges(preg_replace_callback(
            '/^FROM\s+(.+):(.+?)(?:\s+AS\s+.+)?$/m',
            static function (array $fromMatch) use ($dockerfileArguments, &$dockerfileChanges): string {
                [, [$repoName], [$version]] = $fromMatch;

                if (Env::getInstance()->isSkippedForUpdate($repoName)) {
                    return $fromMatch[0][0];
                }

                $repoNameQualified = str_contains($repoName, '/') ?
                    $repoName :
                    sprintf('library/%s', $repoName);

                try {
                    $versionInterpolated = preg_replace_callback(
                        '/\${([^}:]+)}/m',
                        static fn(array $match): string => $dockerfileArguments->get($match[1], $fromMatch[0][1])['value'],
                        $version,
                    );

                    $versionUpdateRegexp = sprintf('/^%s$/U', implode('', array_map(
                        static function (string $part) use ($repoNameQualified): string {
                            static $index = 0;
                            static $root = false;

                            if (0 === $index % 2) {
                                $root = 0 === $index && '' === $part;

                                ++$index;

                                return $part;
                            }

                            ++$index;

                            $pattern = !$root || (2 === $index && Env::getInstance()->isSemanticallyVersioned($repoNameQualified)) ?
                                /** @lang PhpRegExp */
                                '/(\d+)\\\\\.((?:\d\\\\\.)*\d+)/' :
                                /** @lang PhpRegExp */
                                '/(\d+\\\\\.\d+)\\\\\.((?:\d\\\\\.)*\d+)/';

                            return preg_replace($pattern, '$1\\\\.[\\\\d\\\\.]+', $part);
                        },
                        preg_split('/((?:\d\\\\\.)+\d+)/', implode('', array_map(
                            static function (string $name) use ($dockerfileArguments, $fromMatch): string {
                                static $uniqueId = 0;

                                if (0 === $uniqueId % 2) {
                                    ++$uniqueId;

                                    return preg_quote($name, '/');
                                }

                                return sprintf(
                                    '(?P<crc32_%s_%d>.+)',
                                    DockerfileParametersCollection::calculateParameterHash(
                                        $name,
                                        $dockerfileArguments->get($name, $fromMatch[0][1])['valueOffset'],
                                    ),
                                    $uniqueId++,
                                );
                            },
                            preg_split('/\${([^}:]+)}/m', $version, flags: PREG_SPLIT_DELIM_CAPTURE)
                        )), flags: PREG_SPLIT_DELIM_CAPTURE),
                    )));
                } catch (OutOfBoundsException $exception) {
                    fwrite(STDERR, $exception->getMessage());

                    // Cannot interpolate or build version detection, skip
                    return $fromMatch[0][0];
                }

                $newerVersionCheckRegex = sprintf('/^%s$/', implode('', array_map(
                    static function (string $part) use ($repoNameQualified): string {
                        static $index = 0;
                        static $root = false;

                        if (0 === $index % 2) {
                            $root = 0 === $index && '' === $part;

                            ++$index;

                            return preg_quote($part, '/');
                        }

                        ++$index;

                        $pattern = !$root || (2 === $index && Env::getInstance()->isSemanticallyVersioned($repoNameQualified)) ?
                            /** @lang PhpRegExp */
                            '/(\d+)\.((?:\d\.)*\d+)/' :
                            /** @lang PhpRegExp */
                            '/(\d+\.\d+)\.((?:\d\.)*\d+)/';

                        return preg_replace($pattern, '$1\\\\.[\\\\d\\\\.]+', $part);
                    },
                    preg_split('/((?:\d\.)+\d+)/', $versionInterpolated, flags: PREG_SPLIT_DELIM_CAPTURE),
                )));

                try {
                    $authResponse = DockerHub::auth($repoNameQualified);
                    $tagsResponse = DockerHub::tags($repoNameQualified, $authResponse['token']);
                } catch (UnexpectedValueException $exception) {
                    fwrite(STDERR, $exception->getMessage());

                    return $fromMatch[0][0];
                }

                usort($tagsResponse['tags'], static fn(string $a, string $b): int => -strnatcasecmp($a, $b));

                $filteredVersions = array_filter(
                    $tagsResponse['tags'],
                    static fn(string $version) => preg_match($newerVersionCheckRegex, $version) &&
                        preg_match($versionUpdateRegexp, $version),
                );

                $newestMatchingVersion = reset($filteredVersions);

                if (false === $newestMatchingVersion || $newestMatchingVersion === $versionInterpolated) {
                    // Same version, skip
                    return $fromMatch[0][0];
                }

                $dockerfileChanges[] = [
                    'name' => $repoName,
                    'from' => $versionInterpolated,
                    'to'   => $newestMatchingVersion,
                ];

                $updatedFrom = substr_replace(
                    $fromMatch[0][0],
                    $updatedVersion = preg_replace_callback(
                        $versionUpdateRegexp,
                        static function (array $match) use ($dockerfileArguments) {
                            $version = $match[0][0];

                            foreach (array_reverse($match) as $index => [$value, $offset]) {
                                if (\is_string($index) && str_starts_with($index, 'crc32_')) {
                                    preg_match('/^crc32_(.+)_\d+$/', $index, $parameterMatch);
                                    $parameterHash = $parameterMatch[1];

                                    $dockerfileArguments->setByHash($parameterHash, $value);

                                    $version = substr_replace(
                                        $version,
                                        sprintf('${%s}', $dockerfileArguments->getByHash($parameterHash)['name']),
                                        $offset,
                                        \strlen($value),
                                    );
                                }
                            }

                            return $version;
                        },
                        $newestMatchingVersion,
                        flags: PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                    ),
                    $fromMatch[2][1] - $fromMatch[0][1],
                    \strlen($version),
                );

                $dockerfileArguments->updateOffsets($fromMatch[2][1], \strlen($updatedVersion) - \strlen($version));

                return $updatedFrom;
            },
            $dockerfileContents,
            flags: PREG_OFFSET_CAPTURE,
        )));
    }

    // docker-compose changes
    $dockerComposeChanges = [];

    if (Env::getInstance()->shouldUpdateDockerCompose()) {
        $dockerComposeFilePath = Env::getDockerComposeFilePath();

        file_put_contents($dockerComposeFilePath, preg_replace_callback(
            '/^\s+image:\s*(.+):(.+?)\s*$/m',
            static function (array $match) use (&$dockerComposeChanges): string {
                if (Env::getInstance()->isSkippedForUpdate($match[1][0])) {
                    return $match[0][0];
                }

                $repoNameQualified = str_contains($match[1][0], '/') ?
                    $match[1][0] :
                    sprintf('library/%s', $match[1][0]);

                try {
                    $authResponse = DockerHub::auth($repoNameQualified);
                    $tagsResponse = DockerHub::tags($repoNameQualified, $authResponse['token']);
                } catch (UnexpectedValueException $exception) {
                    fwrite(STDERR, $exception->getMessage());

                    return $match[0][0];
                }

                usort($tagsResponse['tags'], static fn(string $a, string $b): int => -strnatcasecmp($a, $b));

                $newerVersionCheckRegex = sprintf('/^%s$/', implode('', array_map(
                    static function (string $part) use ($repoNameQualified): string {
                        static $index = 0;
                        static $root = false;

                        if (0 === $index % 2) {
                            $root = 0 === $index && '' === $part;

                            ++$index;

                            return preg_quote($part, '/');
                        }

                        ++$index;

                        $pattern = !$root || (2 === $index && Env::getInstance()->isSemanticallyVersioned($repoNameQualified)) ?
                            /** @lang PhpRegExp */
                            '/(\d+)\.((?:\d\.)*\d+)/' :
                            /** @lang PhpRegExp */
                            '/(\d+\.\d+)\.((?:\d\.)*\d+)/';

                        return preg_replace($pattern, '$1\\\\.[\\\\d\\\\.]+', $part);
                    },
                    preg_split('/((?:\d\.)+\d+)/', $match[2][0], flags: PREG_SPLIT_DELIM_CAPTURE),
                )));

                $filteredVersions = array_filter(
                    $tagsResponse['tags'],
                    static fn(string $version): bool => (bool) preg_match($newerVersionCheckRegex, $version),
                );

                $newestMatchingVersion = reset($filteredVersions);

                if (false === $newestMatchingVersion || $newestMatchingVersion === $match[2][0]) {
                    // Same version, skip
                    return $match[0][0];
                }

                $dockerComposeChanges[] = [
                    'name' => $match[1][0],
                    'from' => $match[2][0],
                    'to'   => $newestMatchingVersion,
                ];

                return substr_replace($match[0][0], $newestMatchingVersion, $match[2][1] - $match[0][1], \strlen($match[2][0]));
            },
            file_get_contents($dockerComposeFilePath),
            flags: PREG_OFFSET_CAPTURE,
        ));
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    fwrite(STDERR, $e->getTraceAsString());

    exit(2);
}

// Output
writeln('::set-output name=updates-count::%d', \count($dockerfileChanges) + \count($dockerComposeChanges));

$messages = [];

writeln('::group::Dockerfile updates');

if (!empty($dockerfileChanges)) {
    $changes = ['Dockerfile updates:'];

    foreach ($dockerfileChanges as ['name' => $name, 'from' => $from, 'to' => $to]) {
        $changes[] = sprintf('* Update **%s** from `%s` to `%s`.', $name, $from, $to);
        writeln(sprintf('* Update %s from %s to %s.', $name, $from, $to));
    }

    $messages[] = implode(PHP_EOL, $changes);
} else {
    writeln('No dockerfile updates detected.');
}
writeln('::endgroup::');

writeln('::group::docker-compose.yml updates');

if (!empty($dockerComposeChanges)) {
    $changes = ['docker-compose.yml updates:'];

    foreach ($dockerComposeChanges as ['name' => $name, 'from' => $from, 'to' => $to]) {
        $changes[] = sprintf('* Update **%s** from `%s` to `%s`.', $name, $from, $to);
        writeln(sprintf('* Update %s from %s to %s.', $name, $from, $to));
    }

    $messages[] = implode(PHP_EOL, $changes);
} else {
    writeln('No docker-compose.yml updates detected.');
}
writeln('::endgroup::');

writeln('::set-output name=message::%s', strtr(
    implode(PHP_EOL . PHP_EOL, $messages),
    [
        '%'  => '%25',
        "\n" => '%0A',
        "\r" => '%0D',
    ]
));

exit(0);
