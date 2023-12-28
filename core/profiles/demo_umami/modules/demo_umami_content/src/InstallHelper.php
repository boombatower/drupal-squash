<?php

namespace Drupal\demo_umami_content;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a helper class for importing default content.
 *
 * @internal
 *   This code is only for use by the Umami demo: Content module.
 */
class InstallHelper implements ContainerInjectionInterface {

  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * State.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Term ID map.
   *
   * Used to store term IDs created in the import process against
   * vocabulary and row in the source CSV files. This allows the created terms
   * to be cross referenced when creating articles and recipes.
   *
   * @var array
   */
  protected $termIdMap;

  /**
   * Constructs a new InstallHelper object.
   *
   * @param \Drupal\Core\Path\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   */
  public function __construct(AliasManagerInterface $aliasManager, EntityTypeManagerInterface $entityTypeManager, ModuleHandlerInterface $moduleHandler, StateInterface $state, FileSystemInterface $fileSystem) {
    $this->aliasManager = $aliasManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
    $this->state = $state;
    $this->fileSystem = $fileSystem;
    $this->termIdMap = [];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path.alias_manager'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('state'),
      $container->get('file_system')
    );
  }

  /**
   * Imports default contents.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function importContent() {
    $this->importTerms('tags', 'tags.csv')
      ->importTerms('recipe_category', 'recipe_categories.csv')
      ->importEditors()
      ->importArticles()
      ->importRecipes()
      ->importPages()
      ->importBlockContent();
  }

  /**
   * Imports terms for a given vocabulary and filename.
   *
   * @param string $vocabulary
   *   Machine name of vocabulary to which we should save terms.
   * @param string $filename
   *   Filename of the file containing the terms to import.
   *
   * @return $this
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function importTerms($vocabulary, $filename) {
    $module_path = $this->moduleHandler->getModule('demo_umami_content')->getPath();
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    if (($handle = fopen($module_path . "/default_content/languages/en/$filename", 'r')) !== FALSE) {
      $header = fgetcsv($handle);
      while (($data = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($header, $data);
        $term_name = trim($data['term']);

        $term = $term_storage->create([
          'name' => $term_name,
          'vid' => $vocabulary,
          'path' => ['alias' => '/' . Html::getClass($vocabulary) . '/' . Html::getClass($term_name)],
        ]);
        $term->save();
        $this->storeCreatedContentUuids([$term->uuid() => 'taxonomy_term']);
        $this->saveTermId($vocabulary, $data['id'], $term->id());
      }
    }
    return $this;
  }

  /**
   * Retrieves the Term ID of a term saved during the import process.
   *
   * @param string $vocabulary
   *   Machine name of vocabulary to which it was saved.
   * @param int $term_csv_id
   *   The term's ID from the CSV file.
   *
   * @return int
   *   Term ID, or 0 if Term ID could not be found.
   */
  protected function getTermId($vocabulary, $term_csv_id) {
    if (array_key_exists($vocabulary, $this->termIdMap) && array_key_exists($term_csv_id, $this->termIdMap[$vocabulary])) {
      return $this->termIdMap[$vocabulary][$term_csv_id];
    }
    return 0;
  }

  /**
   * Saves a Term ID generated when saving a taxonomy term.
   *
   * @param string $vocabulary
   *   Machine name of vocabulary to which it was saved.
   * @param int $term_csv_id
   *   The term's ID from the CSV file.
   * @param int $tid
   *   Term ID generated when saved in the Drupal database.
   */
  protected function saveTermId($vocabulary, $term_csv_id, $tid) {
    $this->termIdMap[$vocabulary][$term_csv_id] = $tid;
  }

  /**
   * Imports editors.
   *
   * Other users are created as their content is imported. However, editors
   * don't have their own content so are created here instead.
   *
   * @return $this
   */
  protected function importEditors() {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $editors = [
      'Margaret Hopper',
      'Grace Hamilton',
    ];
    foreach ($editors as $name) {
      $user = $user_storage->create([
        'name' => $name,
        'status' => 1,
        'roles' => ['editor'],
        'mail' => mb_strtolower(str_replace(' ', '.', $name)) . '@example.com',
      ]);
      $user->enforceIsNew();
      $user->save();
      $this->storeCreatedContentUuids([$user->uuid() => 'user']);
    }
    return $this;
  }

  /**
   * Imports articles.
   *
   * @return $this
   */
  protected function importArticles() {
    $module_path = $this->moduleHandler->getModule('demo_umami_content')
      ->getPath();
    if (($handle = fopen($module_path . '/default_content/languages/en/articles.csv', "r")) !== FALSE) {
      $uuids = [];
      $header = fgetcsv($handle);
      while (($data = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($header, $data);
        // Prepare content.
        $values = [
          'type' => 'article',
          'title' => $data['title'],
          'moderation_state' => 'published',
        ];
        // Fields mapping starts.
        // Set Body Field.
        if (!empty($data['body'])) {
          $body_path = $module_path . '/default_content/languages/en/article_body/' . $data['body'];
          $body = file_get_contents($body_path);
          if ($body !== FALSE) {
            $values['body'] = [['value' => $body, 'format' => 'basic_html']];
          }
        }
        // Set node alias if exists.
        if (!empty($data['slug'])) {
          $values['path'] = [['alias' => '/' . $data['slug']]];
        }
        // Set field_tags if exists.
        if (!empty($data['tags'])) {
          $values['field_tags'] = [];
          $tags = explode(',', $data['tags']);
          foreach ($tags as $tag_id) {
            if ($tid = $this->getTermId('tags', $tag_id)) {
              $values['field_tags'][] = ['target_id' => $tid];
            }
          }
        }
        // Set article author.
        if (!empty($data['author'])) {
          $values['uid'] = $this->getUser($data['author']);
        }
        // Set Image field.
        if (!empty($data['image'])) {
          $path = $module_path . '/default_content/images/' . $data['image'];
          $values['field_image'] = [
            'target_id' => $this->createFileEntity($path),
            'alt' => $data['alt'],
          ];
        }

        // Create Node.
        $node = $this->entityTypeManager->getStorage('node')->create($values);
        $node->save();
        $uuids[$node->uuid()] = 'node';
      }
      $this->storeCreatedContentUuids($uuids);
      fclose($handle);
    }
    return $this;
  }

  /**
   * Imports recipes.
   *
   * @return $this
   */
  protected function importRecipes() {
    $module_path = $this->moduleHandler->getModule('demo_umami_content')->getPath();

    if (($handle = fopen($module_path . '/default_content/languages/en/recipes.csv', "r")) !== FALSE) {
      $header = fgetcsv($handle);
      $uuids = [];
      while (($data = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($header, $data);
        $values = [
          'type' => 'recipe',
          // Title field.
          'title' => $data['title'],
          'moderation_state' => 'published',
        ];
        // Set article author.
        if (!empty($data['author'])) {
          $values['uid'] = $this->getUser($data['author']);
        }
        // Set node alias if exists.
        if (!empty($data['slug'])) {
          $values['path'] = [['alias' => '/' . $data['slug']]];
        }
        // Set field_image field.
        if (!empty($data['image'])) {
          $image_path = $module_path . '/default_content/images/' . $data['image'];
          $values['field_image'] = [
            'target_id' => $this->createFileEntity($image_path),
            'alt' => $data['alt'],
          ];
        }
        // Set field_summary Field.
        if (!empty($data['summary'])) {
          $values['field_summary'] = [['value' => $data['summary'], 'format' => 'basic_html']];
        }
        // Set field_recipe_category if exists.
        if (!empty($data['recipe_category'])) {
          $values['field_recipe_category'] = [];
          $tags = array_filter(explode(',', $data['recipe_category']));
          foreach ($tags as $tag_id) {
            if ($tid = $this->getTermId('recipe_category', $tag_id)) {
              $values['field_recipe_category'][] = ['target_id' => $tid];
            }
          }
        }
        // Set field_preparation_time Field.
        if (!empty($data['preparation_time'])) {
          $values['field_preparation_time'] = [['value' => $data['preparation_time']]];
        }
        // Set field_cooking_time Field.
        if (!empty($data['cooking_time'])) {
          $values['field_cooking_time'] = [['value' => $data['cooking_time']]];
        }
        // Set field_difficulty Field.
        if (!empty($data['difficulty'])) {
          $values['field_difficulty'] = $data['difficulty'];
        }
        // Set field_number_of_servings Field.
        if (!empty($data['number_of_servings'])) {
          $values['field_number_of_servings'] = [['value' => $data['number_of_servings']]];
        }
        // Set field_ingredients Field.
        if (!empty($data['ingredients'])) {
          $ingredients = explode(',', $data['ingredients']);
          $values['field_ingredients'] = [];
          foreach ($ingredients as $ingredient) {
            $values['field_ingredients'][] = ['value' => $ingredient];
          }
        }
        // Set field_recipe_instruction Field.
        if (!empty($data['recipe_instruction'])) {
          $recipe_instruction_path = $module_path . '/default_content/languages/en/recipe_instructions/' . $data['recipe_instruction'];
          $recipe_instructions = file_get_contents($recipe_instruction_path);
          if ($recipe_instructions !== FALSE) {
            $values['field_recipe_instruction'] = [['value' => $recipe_instructions, 'format' => 'basic_html']];
          }
        }
        // Set field_tags if exists.
        if (!empty($data['tags'])) {
          $values['field_tags'] = [];
          $tags = array_filter(explode(',', $data['tags']));
          foreach ($tags as $tag_id) {
            if ($tid = $this->getTermId('tags', $tag_id)) {
              $values['field_tags'][] = ['target_id' => $tid];
            }
          }
        }

        $node = $this->entityTypeManager->getStorage('node')->create($values);
        $node->save();
        $uuids[$node->uuid()] = 'node';
      }
      $this->storeCreatedContentUuids($uuids);
      fclose($handle);
    }
    return $this;
  }

  /**
   * Imports pages.
   *
   * @return $this
   */
  protected function importPages() {
    if (($handle = fopen($this->moduleHandler->getModule('demo_umami_content')->getPath() . '/default_content/languages/en/pages.csv', "r")) !== FALSE) {
      $headers = fgetcsv($handle);
      $uuids = [];
      while (($data = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $data);

        // Prepare content.
        $values = [
          'type' => 'page',
          'title' => $data['title'],
          'moderation_state' => 'published',
        ];
        // Fields mapping starts.
        // Set Body Field.
        if (!empty($data['body'])) {
          $values['body'] = [['value' => $data['body'], 'format' => 'basic_html']];
        }
        // Set node alias if exists.
        if (!empty($data['slug'])) {
          $values['path'] = [['alias' => '/' . $data['slug']]];
        }
        // Set article author.
        if (!empty($data['author'])) {
          $values['uid'] = $this->getUser($data['author']);
        }

        // Create Node.
        $node = $this->entityTypeManager->getStorage('node')->create($values);
        $node->save();
        $uuids[$node->uuid()] = 'node';
      }
      $this->storeCreatedContentUuids($uuids);
      fclose($handle);
    }
    return $this;
  }

  /**
   * Imports block content entities.
   *
   * @return $this
   */
  protected function importBlockContent() {
    $module_path = $this->moduleHandler->getModule('demo_umami_content')->getPath();
    $copyright_message = '&copy; ' . date("Y") . ' Terms & Conditions';
    $block_content_entities = [
      'umami_home_banner' => [
        'uuid' => '9aadf4a1-ded6-4017-a10d-a5e043396edf',
        'info' => 'Umami Home Banner',
        'type' => 'banner_block',
        'field_title' => [
          'value' => 'Super easy vegetarian pasta bake',
        ],
        'field_content_link' => [
          'uri' => 'internal:' . call_user_func(function () {
            $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['title' => 'Super easy vegetarian pasta bake']);
            $node = reset($nodes);
            return $this->aliasManager->getAliasByPath('/node/' . $node->id());
          }),
          'title' => 'View recipe',
        ],
        'field_summary' => [
          'value' => 'A wholesome pasta bake is the ultimate comfort food. This delicious bake is super quick to prepare and an ideal midweek meal for all the family.',
        ],
        'field_banner_image' => [
          'target_id' => $this->createFileEntity($module_path . '/default_content/images/veggie-pasta-bake-hero-umami.jpg'),
          'alt' => 'Mouth watering vegetarian pasta bake with rich tomato sauce and cheese toppings',
        ],
      ],
      'umami_recipes_banner' => [
        'uuid' => '4c7d58a3-a45d-412d-9068-259c57e40541',
        'info' => 'Umami Recipes Banner',
        'type' => 'banner_block',
        'field_title' => [
          'value' => 'Vegan chocolate and nut brownies',
        ],
        'field_content_link' => [
          'uri' => 'internal:' . call_user_func(function () {
            $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['title' => 'Vegan chocolate and nut brownies']);
            $node = reset($nodes);
            return $this->aliasManager->getAliasByPath('/node/' . $node->id());
          }),
          'title' => 'View recipe',
        ],
        'field_summary' => [
          'value' => 'These sumptuous brownies should be gooey on the inside and crisp on the outside. A perfect indulgence!',
        ],
        'field_banner_image' => [
          'target_id' => $this->createFileEntity($module_path . '/default_content/images/vegan-brownies-hero-umami.jpg'),
          'alt' => 'A stack of chocolate and pecan brownies, sprinkled with pecan crumbs and crushed walnut, fresh out of the oven',
        ],
      ],
      'umami_disclaimer' => [
        'uuid' => '9b4dcd67-99f3-48d0-93c9-2c46648b29de',
        'info' => 'Umami disclaimer',
        'type' => 'disclaimer_block',
        'field_disclaimer' => [
          'value' => '<strong>Umami Magazine & Umami Publications</strong> is a fictional magazine and publisher for illustrative purposes only.',
          'format' => 'basic_html',
        ],
        'field_copyright' => [
          'value' => $copyright_message,
          'format' => 'basic_html',
        ],
      ],
      'umami_footer_promo' => [
        'uuid' => '924ab293-8f5f-45a1-9c7f-2423ae61a241',
        'info' => 'Umami footer promo',
        'type' => 'footer_promo_block',
        'field_title' => [
          'value' => 'Umami Food Magazine',
        ],
        'field_summary' => [
          'value' => 'Skills and know-how. Magazine exclusive articles, recipes and plenty of reasons to get your copy today.',
        ],
        'field_content_link' => [
          'uri' => 'internal:' . call_user_func(function () {
            $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['title' => 'About Umami']);
            $node = reset($nodes);
            return $this->aliasManager->getAliasByPath('/node/' . $node->id());
          }),
          'title' => 'Find out more',
        ],
        'field_promo_image' => [
          'target_id' => $this->createFileEntity($module_path . '/default_content/images/umami-bundle.png'),
          'alt' => '3 issue bundle of the Umami food magazine',
        ],
      ],
    ];

    // Create block content.
    foreach ($block_content_entities as $values) {
      $block_content = $this->entityTypeManager->getStorage('block_content')->create($values);
      $block_content->save();
      $this->storeCreatedContentUuids([$block_content->uuid() => 'block_content']);
    }
    return $this;
  }

  /**
   * Deletes any content imported by this module.
   *
   * @return $this
   */
  public function deleteImportedContent() {
    $uuids = $this->state->get('demo_umami_content_uuids', []);
    $by_entity_type = array_reduce(array_keys($uuids), function ($carry, $uuid) use ($uuids) {
      $entity_type_id = $uuids[$uuid];
      $carry[$entity_type_id][] = $uuid;
      return $carry;
    }, []);
    foreach ($by_entity_type as $entity_type_id => $entity_uuids) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entities = $storage->loadByProperties(['uuid' => $entity_uuids]);
      $storage->delete($entities);
    }
    return $this;
  }

  /**
   * Looks up a user by name, if it is missing the user is created.
   *
   * @param string $name
   *   Username.
   *
   * @return int
   *   User ID.
   */
  protected function getUser($name) {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $users = $user_storage->loadByProperties(['name' => $name]);;
    if (empty($users)) {
      // Creating user without any password.
      $user = $user_storage->create([
        'name' => $name,
        'status' => 1,
        'roles' => ['author'],
        'mail' => mb_strtolower(str_replace(' ', '.', $name)) . '@example.com',
      ]);
      $user->enforceIsNew();
      $user->save();
      $this->storeCreatedContentUuids([$user->uuid() => 'user']);
      return $user->id();
    }
    $user = reset($users);
    return $user->id();
  }

  /**
   * Creates a file entity based on an image path.
   *
   * @param string $path
   *   Image path.
   *
   * @return int
   *   File ID.
   */
  protected function createFileEntity($path) {
    $filename = basename($path);
    try {
      $uri = $this->fileSystem->copy($path, 'public://' . $filename, FileSystemInterface::EXISTS_REPLACE);
    }
    catch (FileException $e) {
      $uri = FALSE;
    }
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();
    $this->storeCreatedContentUuids([$file->uuid() => 'file']);
    return $file->id();
  }

  /**
   * Stores record of content entities created by this import.
   *
   * @param array $uuids
   *   Array of UUIDs where the key is the UUID and the value is the entity
   *   type.
   */
  protected function storeCreatedContentUuids(array $uuids) {
    $uuids = $this->state->get('demo_umami_content_uuids', []) + $uuids;
    $this->state->set('demo_umami_content_uuids', $uuids);
  }

}
