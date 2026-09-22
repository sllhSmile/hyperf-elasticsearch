<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

use Composer\InstalledVersions;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;

/** 根据已安装官方客户端主版本创建对应 adapter。 */
final class AdapterFactory
{
    /** 按显式或自动检测的主版本包装官方客户端，拒绝不支持的版本。 */
    public static function fromClient(object $client, ?int $major = null): OfficialClientAdapter
    {
        $major ??= self::detectMajor();
        return match ($major) {
            ClientMajor::ES7 => new Elastic7Adapter($client),
            ClientMajor::ES8 => new Elastic8Adapter($client),
            ClientMajor::ES9 => new Elastic9Adapter($client),
            default => throw new ConfigurationException("Unsupported Elasticsearch PHP client major version [{$major}]."),
        };
    }

    /** 优先读取 Composer 版本元数据，缺失时再根据官方客户端类名推断主版本。 */
    public static function detectMajor(): int
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('elasticsearch/elasticsearch')) {
            $version = InstalledVersions::getPrettyVersion('elasticsearch/elasticsearch')
                ?: InstalledVersions::getVersion('elasticsearch/elasticsearch');
            $supportedMajors = implode('', ClientMajor::SUPPORTED);
            if (is_string($version)
                && preg_match("/(?:^|[^0-9])([{$supportedMajors}])(?:\\.|$)/", $version, $matches) === 1
            ) {
                return (int) $matches[1];
            }
        }

        // 类名回退只能区分 ES7 与新版命名空间；正常 Composer 安装会在上方得到精确版本。
        if (class_exists('Elasticsearch\\ClientBuilder')) {
            return ClientMajor::ES7;
        }
        if (class_exists('Elastic\\Elasticsearch\\ClientBuilder')) {
            return ClientMajor::ES9;
        }
        throw new ConfigurationException(
            'No supported Elasticsearch PHP client is installed. Install elasticsearch/elasticsearch 7, 8 or 9.'
        );
    }
}
