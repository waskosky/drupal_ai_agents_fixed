<?php

namespace Drupal\ai_agents\PluginManager;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Token;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai_agents\Attribute\AiAgent;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\Service\AgentHelper;
use Drupal\ai_agents\Service\ArtifactHelper;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides an AI Agent plugin manager.
 *
 * @see \Drupal\ai_agents\Attribute\AiAgent
 * @see \Drupal\ai_agents\PluginInterfaces\AiAgentInterface
 * @see plugin_api
 */
class AiAgentManager extends DefaultPluginManager {

  /**
   * Constructs an AI Agents object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallPluginManager
   *   The function call plugin manager.
   * @param \Drupal\ai_agents\Service\AgentHelper $agentHelper
   *   The agent helper.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderPluginManager
   *   The AI provider plugin manager.
   * @param \Drupal\ai_agents\Service\ArtifactHelper $artifactHelper
   *   The artifact helper service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected FunctionCallPluginManager $functionCallPluginManager,
    protected AgentHelper $agentHelper,
    protected Token $token,
    protected EventDispatcherInterface $eventDispatcher,
    protected AiProviderPluginManager $aiProviderPluginManager,
    protected ArtifactHelper $artifactHelper,
    protected UuidInterface $uuid,
  ) {
    parent::__construct(
      'Plugin/AiAgent',
      $namespaces,
      $module_handler,
      AiAgentInterface::class,
      AiAgent::class,
    );
    $this->alterInfo('ai_agents_info');
    $this->setCacheBackend($cache_backend, 'ai_agents_plugins');
  }

  /**
   * Creates a plugin instance of a AI Agent.
   *
   * @param string $plugin_id
   *   The ID of the plugin being instantiated.
   * @param array $configuration
   *   An array of configuration relevant to the plugin instance.
   *
   * @return \Drupal\ai\Service\FunctionCalling\FunctionCallInterface
   *   The function call.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   If the instance cannot be created, such as if the ID is invalid.
   */
  public function createInstance($plugin_id, array $configuration = []): AiAgentInterface {
    $definition = $this->getDefinition($plugin_id, FALSE);

    // Handle configuration-backed agents that are exposed as pseudo-plugins.
    if (isset($definition['custom_type']) && $definition['custom_type'] === 'config') {
      $agent = $this->entityTypeManager->getStorage('ai_agent')->load($plugin_id);
      if (!$agent) {
        throw new PluginException(sprintf('Unable to load AI agent configuration for "%s".', $plugin_id));
      }

      return new AiAgentEntityWrapper(
        $agent,
        $this->currentUser,
        $this->entityTypeManager,
        $this->functionCallPluginManager,
        $this->agentHelper,
        $this->token,
        $this->eventDispatcher,
        $this->aiProviderPluginManager,
        $this->artifactHelper,
        $this->uuid,
      );
    }

    return parent::createInstance($plugin_id, $configuration);
  }

  /**
   * Get agent definitions that implements a specific tool.
   *
   * @param string $tool
   *   The tool id to search for.
   *
   * @return array
   *   An array of agent definitions that implement the tool.
   */
  public function getAgentsByTool(string $tool): array {
    $agents = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      if (isset($definition['custom_type']) && $definition['custom_type'] === 'config') {
        // Load the plugin instance.
        /** @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $plugin */
        $plugin = $this->createInstance($id);
        // Get the actual configuration entity.
        $entity = $plugin->getAiAgentEntity();
        if (in_array($tool, array_keys($entity->get('tools')))) {
          $agents[$id] = $definition;
        }
      }
    }
    return $agents;
  }

  

  /**
   * Finds plugin definitions.
   *
   * @return array
   *   List of definitions to store in cache.
   */
  protected function findDefinitions(): array {
    $definitions = parent::findDefinitions();

    // Filter out plugins that depend on modules not present.
    foreach ($definitions as $id => $definition) {
      if (!empty($definition['module_dependencies'])) {
        foreach ($definition['module_dependencies'] as $module) {
          if (!$this->providerExists($module)) {
            unset($definitions[$id]);
            break;
          }
        }
      }
    }

    // During site installation, the entity system isn't fully initialized.
    // Explicitly skip merging configuration entities to avoid triggering
    // entity discovery while core is building.
    if (InstallerKernel::installationAttempted()) {
      return $definitions;
    }

    // Safely merge in Agent Configuration entities as pseudo-plugins.
    // During installation the entity system may not be fully built; avoid
    // touching the entity type manager in that case.
    try {
      // Attempt to load config entities. If the entity type is not yet
      // available, this will throw and we simply skip merging for now.
      $storage = $this->entityTypeManager->getStorage('ai_agent');
      $agent_configurations = $storage->loadMultiple();
      foreach ($agent_configurations as $id => $entity) {
        if (!isset($definitions[$id])) {
          $definitions[$id] = [
            'id' => $id,
            'label' => $entity->label(),
            'custom_type' => 'config',
          ];
        }
      }
    }
    catch (\Throwable $e) {
      // Swallow any errors occurring while the entity system is not ready
      // (e.g., during install or cache rebuild before entity definitions).
    }

    return $definitions;
  }

}
