<?php namespace Geccomedia\Weclapp\Models;

use Geccomedia\Weclapp\Model;

class Article extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'article';

    /**
     * Belongs-to relation to Unit.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unitId');
    }

    /**
     * Belongs-to relation to ArticleCategory.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function articleCategory()
    {
        return $this->belongsTo(ArticleCategory::class, 'articleCategoryId');
    }
}
