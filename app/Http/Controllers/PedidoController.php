<?php

namespace App\Http\Controllers;

use App\Pedido;
use App\Producto;
use App\Movimiento;
use App\Usuario;
use App\Trato;
use App\Traits\CanAccess;

use Illuminate\Http\Request;

class PedidoController extends Controller
{
    use CanAccess;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {

        if ($this->CanUserOficina($request->user(), $request->oficina_id)) {

            $pedidos = Pedido::where([['oficina_id', '=', $request->oficina_id]])->get();

            $pack = [];

            foreach ($pedidos as $pedido) {

                $evaluado_por = $pedido->evaluador ? $pedido->evaluador->name : "";

                $values = [
                    "pedido_id" => $pedido->id,
                    "user" => $pedido->user->name,
                    "evaluado_por" => $evaluado_por,
                    "estado" => $pedido->estado,
                    "fecha" => $pedido->fecha,
                    "almacen" => $pedido->almacene->nombre
                ];

                array_push($pack, $values);
            }

            return $pack;

        }

        if ($this->CanAdminAlmacen($request->user(), $request->almacene_id)) {

            $pedidos = Pedido::where([['almacene_id', '=', $request->almacene_id]])->get();

            $pack = [];

            foreach ($pedidos as $pedido) {

                $evaluado_por = $pedido->evaluador ? $pedido->evaluador->name : "";

                $values = [
                    "pedido_id" => $pedido->id,
                    "user" => $pedido->user->name,
                    "evaluado_por" => $evaluado_por,
                    "estado" => $pedido->estado,
                    "fecha" => $pedido->fecha,
                    "almacen" => $pedido->almacene->nombre,
                    "oficina" => $pedido->oficina->nombre
                ];

                array_push($pack, $values);
            }

            return $pack;
        }

        return ["error" => true];
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        if ($this->CanUserAlmacen($request->user(), $request->oficina_id, $request->almacene_id)) {
           
            $pedido = new Pedido();
            $pedido->user_id = $request->user()->id;
            $pedido->almacene_id = $request->almacene_id;
            $pedido->oficina_id = $request->oficina_id;
            $pedido->comentario_usuario = $request->comentario_usuario;
            $pedido->fecha = date('Y-m-d H:i:s');
            $pedido->estado = 0;
            $pedido->save();

            $aprobado= true;
            $preparacion = 0;

            $tratos = Trato::where('oficina_id', '=', $request->oficina_id)->get();
            
            foreach ($request->productos as $item) {

                $producto = Producto::findOrFail($item['producto_id']);

                if ($producto->preparacion > $preparacion) {
                    $preparacion = $producto->preparacion;
                }

                $key = array_search($producto->id, array_column($tratos->toArray(), 'producto_id'));   

                $minimo = $producto->minimo;
                $maximo = $producto->maximo;

                if (FALSE !== $key) {
                    $minimo = $tratos[$key]->minimo;
                    $maximo = $tratos[$key]->maximo;
                }

                $fecha_actual = date("d-m-Y");
                $fecha_anterior = date("d-m-Y", strtotime($fecha_actual . "- " . $producto->frecuencia . " days")); 

                $cantidad_actual = Pedido::where([['almacene_id', '=', $request->almacene_id], ['oficina_id', '=', $request->oficina_id], ['estado', '!=', 4]])
                    ->whereBetween('fecha', [$fecha_anterior, $fecha_actual])
                    ->with('productos')
                    ->get()
                    ->pluck('productos')
                    ->flatten()
                    ->where('id', $producto->id)
                    ->where('pivot.estado', '!=', 2)
                    ->sum('pivot.cantidad');

                if ($minimo > $item['cantidad'] || $maximo < ($item['cantidad'] + $cantidad_actual)) {
                    $aprobado = false;
                }

            }

            $estadoProducto = $aprobado ? 1 : 0;

            foreach ($request->productos as $item) {
                $pedido->productos()->attach($item['producto_id'], ['cantidad' => $item['cantidad'], 'estado' => $estadoProducto]);
            }

            $pedido->estado = $aprobado ? 1 : 0;
            $pedido->preparacion = $preparacion;
            $pedido->save();

            return ["aprobado" => $aprobado]; 

        }

        return ["error" => true];

    }
    
    public function show(Request $request, Pedido $pedido)
    {

        if ($this->CanUserOficina($request->user(), $request->oficina_id)) {

            if ($pedido->oficina_id != $request->oficina_id) {
                return ["error" => true];
            }

            $pro_pack = [];

            foreach ($pedido->productos as $producto) {

                $values = [
                    "producto_id" => $producto->pivot->producto_id,
                    "codigo" => $producto->codigo,
                    "nombre" => $producto->nombre,
                    "cantidad" => $producto->pivot->cantidad,
                    "estado" => $producto->pivot->estado,
                    "stock" => $producto->stock,
                    "alerta" => $producto->alerta,
                ];

                array_push($pro_pack, $values);

            }

            $evaluado_por = $pedido->evaluador ? $pedido->evaluador->name : "";
            $retirado_por = $pedido->retirador ? $pedido->retirador->name : "";

            $output = [
                "pedido_id" => $pedido->id,
                "estado" => $pedido->estado,
                "user" => $pedido->user->name,
                "user_id" => $pedido->user->id,
                "oficina_id" => $pedido->oficina_id,
                "oficina" => $pedido->oficina->nombre,
                "almacen" => $pedido->almacene->nombre,
                "almacene_id" => $pedido->almacene_id, 
                "evaluado_por" => $evaluado_por,
                "retirado_por" => $retirado_por,
                "preparacion" => $pedido->preparacion,
                "comentario_usuario" => $pedido->comentario_usuario,
                "comentario_administrador" => $pedido->comentario_administrador,
                "fecha" => $pedido->fecha,
                "productos" => $pro_pack,
            ];

            return $output;

        }

        if ($this->CanAdminAlmacen($request->user(), $request->almacene_id)) {

            if ($pedido->almacene_id != $request->almacene_id) {
                return ["error" => true];
            }

            $pro_pack = [];

            foreach ($pedido->productos as $producto) {

                $values = [
                    "producto_id" => $producto->pivot->producto_id,
                    "codigo" => $producto->codigo,
                    "nombre" => $producto->nombre,
                    "cantidad" => $producto->pivot->cantidad,
                    "estado" => $producto->pivot->estado,
                    "minimo" => $producto->minimo,
                    "maximo" => $producto->maximo,
                    "stock" => $producto->stock,
                    "alerta" => $producto->alerta,
                ];

                array_push($pro_pack, $values);
            }

            $evaluado_por = $pedido->evaluador ? $pedido->evaluador->name : "";
            $retirado_por = $pedido->retirador ? $pedido->retirador->name : "";

            $output = [
                "pedido_id" => $pedido->id,
                "estado" => $pedido->estado,
                "user" => $pedido->user->name,
                "user_id" => $pedido->user->id,
                "oficina_id" => $pedido->oficina_id,
                "oficina" => $pedido->oficina->nombre,
                "almacen" => $pedido->almacene->nombre,
                "almacene_id" => $pedido->almacene_id,
                "evaluado_por" => $evaluado_por,
                "retirado_por" => $retirado_por,
                "preparacion" => $pedido->preparacion,
                "comentario_usuario" => $pedido->comentario_usuario,
                "comentario_administrador" => $pedido->comentario_administrador,
                "fecha" => $pedido->fecha,
                "productos" => $pro_pack
            ];

            return $output;

        }

        return ["error" => true];

    }


    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Producto  $producto
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Pedido $pedido)
    {

        if ($this->CanUserOficina($request->user(), $request->oficina_id)) {

            if ($request->estado == 4 && $request->oficina_id == $pedido->oficina_id) {
                try {
                    $pedido->estado = $request->estado;
                    $pedido->evaluado_por = $request->user()->id;
                    foreach ($pedido->productos as $item) {
                        $pedido->productos()->updateExistingPivot($item->id, ['estado' => 2]);
                    }
                    $pedido->save();
                } catch (Exception $e) {
                    return ["error" => true];
                }
                return ["error" => false];
            }

            if ($request->estado == 3 && $request->oficina_id == $pedido->oficina_id) {
                try {
                    $pedido->estado = $request->estado;
                    $pedido->retirado_por = $request->user()->id;
                    $pedido->save();
                } catch (Exception $e) {
                    return ["error" => true];
                }
                return ["error" => false];
            }
            
        }

        if ($this->CanAdminAlmacen($request->user(), $request->almacene_id)) {

            if ($request->has('preparacion')) {
                if ($request->preparacion != $pedido->preparacion && $request->almacene_id == $pedido->almacene_id) {
                    try {
                        $pedido->preparacion = $request->preparacion;
                        $pedido->save();
                    } catch (Exception $e) {
                        return ["error" => true];
                    }
                    return ["error" => false];
                }
            }

            if ($request->estado == 3 && $request->almacene_id == $pedido->almacene_id) {
                try {
                    $pedido->estado = $request->estado;
                    $pedido->save();
                } catch (Exception $e) {
                    return ["error" => true];
                }
                return ["error" => false];
            }

            if ($request->estado == 4 && $request->almacene_id == $pedido->almacene_id) {
                try {
                    $pedido->estado = $request->estado;
                    $pedido->evaluado_por = $request->user()->id;
                    foreach ($pedido->productos as $item) {
                        $pedido->productos()->updateExistingPivot($item->id, ['estado' => 2]);
                    }
                    $pedido->comentario_administrador = $request->comentario_administrador; 
                    $pedido->save();
                } catch (Exception $e) {
                    return ["error" => true];
                }
                return ["error" => false];
            }

            if ($request->estado == 2 && $request->almacene_id == $pedido->almacene_id) {

                $posible = true;

                $productos = $pedido->productos->where('pivot.estado', 1);

                foreach ($productos as $producto) {
                    if ($producto->pivot->cantidad > $producto->stock) {
                        $posible = false;
                    }
                }

                if ($posible) {
                    try {
                        
                       foreach ($productos as $pro) {

                        $producto = Producto::findOrFail($pro->id);

                        $cantidad = $pro->pivot->cantidad;
                        $original = $producto->stock;
                        $nuevo = $original - $cantidad;
                        $producto->stock = $nuevo;
                        $producto->save();

                        Movimiento::create(
                            [
                                'oficina_id' => $pedido->oficina_id,
                                'producto_id' => $producto->id,
                                'original' => $original,
                                'nuevo' => $nuevo,
                                'fecha' => date('Y-m-d H:i:s'),
                                'tipo' => "2",
                            ]
                        );

                        }

                        $pedido->estado = $request->estado;
                        $pedido->save(); 

                    } catch (Exception $e) {
                        return ["error" => 'true'];
                    }
                }

                return ["sucess" => $posible, "error" => false];

            }

            if ($request->estado == 1 && $request->almacene_id == $pedido->almacene_id) {

                $estadoPedido = 1;

                try {
                    
                   foreach ($request->pack_aprobado as $item) {

                        if (!$item['aprobado']) {
                            $estadoPedido = 5;
                        }

                        if ($item['aprobado']) {
                            $pedido->productos()->updateExistingPivot($item['producto_id'], ['estado' => 1, 'cantidad' => $item['cantidad']]);
                        } else {
                            $pedido->productos()->updateExistingPivot($item['producto_id'], ['estado' => 2]);
                        }

                    } 

                } catch (Exception $e) {
                    return ["error" => true];
                }

                try {
                    $pedido->estado = $estadoPedido;
                    $pedido->evaluado_por = $request->user()->id;
                    $pedido->save();
                } catch (Exception $e) {
                    return ["error" => true];
                }

                return ["error" => false];
            }

        }

        return ["error" => true];
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Pedido  $pedido
     * @return \Illuminate\Http\Response
     */

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Pedido  $pedido
     * @return \Illuminate\Http\Response
     */
    public function destroy(Pedido $pedido)
    {
        //
    }
}
