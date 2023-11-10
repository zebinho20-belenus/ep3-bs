<?php
namespace Payum\Core\Model;

use Payum\Core\Storage\IdentityInterface;

class Identity implements IdentityInterface
{
    /**
     * @var string
     */
    protected $class;

    /**
     * @var mixed
     */
    protected $id;

    /**
     * @param mixed         $id
     * @param string|object $class
     */
    public function __construct($id, $class)
    {
        $this->id = $id;
        $this->class = is_object($class) ? get_class($class) : $class;
    }

    /**
     * {@inheritDoc}
     */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * {@inheritDoc}
     */
    public function getId()
    {
        return $this->id;
    }

    public function __serialize()                              
    {                                                        
        return [
            'id' => $this->id,
            'class' => $this->class,
        ];
    }                                                         

    /**                                                           
     *  * {@inheritDoc}                                              
     *   */                                                           
    public function serialize()                                 
    {                                                             
            return serialize(array($this->id, $this->class));         
    }                                                

    public function __unserialize($serialized)                         
    {                                                                
        $this->id = $serialized['id'];
        $this->class = $serialized['class'];   
    }                                                                
                                                                  
    /**                                                           
     *  * {@inheritDoc}                                              
     *   */                                                           
    public function unserialize($serialized)                    
    {                                                             
            list($this->id, $this->class) = unserialize($serialized); 
    }                                                             
                                                                  
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->class.'#'.$this->id;
    }
}
