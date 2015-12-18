<?php

namespace EloquentVersioned\Traits;

use EloquentVersioned\Builder as VersionedBuilder;
use EloquentVersioned\Scopes\VersioningScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait Versioned
{
    protected $minorAttributes = [];

    protected $isVersioned = true;

    protected $hideVersioned = [
        VersionedBuilder::COLUMN_IS_CURRENT_VERSION,
        VersionedBuilder::COLUMN_MODEL_ID,
    ];

    public static function bootVersioned()
    {
        static::addGlobalScope(new VersioningScope());
    }

    protected function getHideVersioned($hide = [])
    {
        return array_merge($hide, $this->hideVersioned);
    }

    /*
     * ACCESSORS + MUTATORS
     */

    /**
     * @param $query
     *
     * @return VersionedBuilder
     */
    public function newEloquentBuilder($query)
    {
        return new VersionedBuilder($query);
    }

    /**
     * @return array
     */
    public function attributesToArray($hide = [])
    {
        $parentAttributes = parent::attributesToArray();

        if ((!$this->isVersioned) || ($this->{static::getIsCurrentVersionColumn()} == false)) {
            return $parentAttributes;
        }

        $attributes = [];
        foreach ($parentAttributes as $key => $value) {
            if (!in_array($key, $this->getHideVersioned($hide))) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param Builder $query
     *
     * @return Builder
     */
    protected function setKeysForSaveQuery(Builder $query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery())
            ->where($this->getQualifiedIsCurrentVersionColumn(), true);

        return $query;
    }

    /**
     * @param array $options
     *
     * @return mixed
     */
    public function saveMinor(array $options = [])
    {
        return parent::save($options);
    }

    /**
     * Save a new version of the model
     *
     * @param array $options
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        if ($this->exists && $this->onlyHasMinorEdits()) {
            return $this->saveMinor($options);
        }

        $query = $this->newQueryWithoutScopes();

        $db = $this->getConnection();

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This provides a chance for any
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            if ($this->isDirty()) {
                $saved = $db->transaction(function () use ($query, $db, $options) {
                    $oldVersion = $this->replicate(array_merge([$this->getKeyName()], array_keys($this->getNewAttributes())));
                    $oldVersion->forceFill(array_except($this->getOriginal(), $this->getKeyName()));
                    $oldVersion->{static::getIsCurrentVersionColumn()} = false;

                    // trigger the update event
                    if ($this->fireModelEvent('updating') === false) {
                        return false;
                    }

                    $this->{static::getVersionColumn()} = static::getNextVersion($this->{static::getModelIdColumn()});

                    if ($saved = $this->performUpdate($query, $options)) {
                        $this->performVersionedInsert($query, $oldVersion);
                        $this->fireModelEvent('updated', false);
                    }

                    return $saved;
                });
            }
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            if ($this->{static::getModelIdColumn()} === null) {
                $this->{static::getModelIdColumn()} = static::getNextModelId();
            }
            $this->{static::getVersionColumn()}          = 1;
            $this->{static::getIsCurrentVersionColumn()} = true;
            $saved                                       = $this->performInsert($query, $options);
        }

        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }

    protected function insertAndSetId(Builder $query, $attributes)
    {
        $id = $query->insertGetId($attributes, $keyName = $this->primaryKey);

        $this->setAttribute($keyName, $id);
    }

    /*
     * EXTENSIONS
     */

    /**
     * @param Builder $query
     * @param Model   $model
     */
    public function performVersionedInsert(Builder $query, Model $model)
    {
        $model->fireModelEvent('creating');

        return $query->insert($model->getAttributes());
    }

    /**
     * @param bool $isVersioned
     *
     * @return $this
     */
    public function setIsVersioned($isVersioned = true)
    {
        $this->isVersioned = $isVersioned;

        return $this;
    }

    /**
     * @return mixed
     */
    public static function getNextModelId()
    {
        return (new static)->getConnection()->table((new static)->getTable())
            ->max(static::getModelIdColumn()) + 1;
    }

    /**
     * @param int $modelId
     *
     * @return int
     */
    public static function getNextVersion($modelId)
    {
        return (new static)->getConnection()->table((new static)->getTable())
            ->where(static::getModelIdColumn(), $modelId)
            ->max(static::getVersionColumn()) + 1;
    }

    /**
     * @return string
     */
    public static function getModelIdColumn()
    {
        return VersionedBuilder::COLUMN_MODEL_ID;
    }

    /**
     * @return string
     */
    public static function getQualifiedModelIdColumn()
    {
        return (new static)->getTable() . '.' . static::getModelIdColumn();
    }

    /**
     * @return string
     */
    public static function getVersionColumn()
    {
        return VersionedBuilder::COLUMN_VERSION;
    }

    /**
     * @return string
     */
    public static function getQualifiedVersionColumn()
    {
        return (new static)->getTable() . '.' . static::getVersionColumn();
    }

    /**
     * @return string
     */
    public static function getIsCurrentVersionColumn()
    {
        return VersionedBuilder::COLUMN_IS_CURRENT_VERSION;
    }

    /**
     * @return string
     */
    public static function getQualifiedIsCurrentVersionColumn()
    {
        return (new static)->getTable() . '.' . static::getIsCurrentVersionColumn();
    }

    /**
     * @return mixed
     */
    public static function withOldVersions()
    {
        return (new static)->newQueryWithoutScope(new VersioningScope);
    }

    /**
     * @return mixed
     */
    public static function onlyOldVersions()
    {
        return (new static)->newQueryWithoutScope(new VersioningScope)
            ->where(static::getQualifiedIsCurrentVersionColumn(), false);
    }

    /**
     * @return mixed
     */
    public function getPreviousModel()
    {
        if ($this->version === 1) {
            return null;
        }

        return $this->withOldVersions()
            ->where('model_id', $this->model_id)
            ->where('version', ($this->version - 1))
            ->first();
    }

    /**
     * @return mixed
     */
    public function getNextModel()
    {
        if ($this->is_current_version === true) {
            return null;
        }

        return $this->withOldVersions()
            ->where('model_id', $this->model_id)
            ->where('version', ($this->version + 1))
            ->first();
    }

    public function onlyHasMinorEdits()
    {
        $changedAttributes = $this->getDirty();

        foreach ($changedAttributes as $key => $value) {
            if (!in_array($key, $this->minorAttributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the attributes that have been added since last sync.
     *
     * @return array
     */
    public function getNewAttributes()
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }
}
