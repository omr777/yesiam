import { inject, Injectable } from '@angular/core';

import {
  HttpClient,
  HttpHeaders,
} from '@angular/common/http';

import { Observable, map } from 'rxjs';

/*
|--------------------------------------------------------------------------
| YESIAM - DEPARTMENT SERVICE
|--------------------------------------------------------------------------
|
| FASE 19.5.3
|
| Servicio de consulta de departamentos
| para el proceso Joiner.
|
| Endpoint:
|
| GET /api/departments
|
| Responsabilidades:
|
| 1. Consultar el catálogo real de Laravel.
| 2. Identificar los departamentos activos.
| 3. Proporcionar opciones para el formulario.
| 4. Conservar los errores de autorización.
|
| No creamos ni modificamos departamentos.
|
*/

/*
|--------------------------------------------------------------------------
| MODELO DE DEPARTAMENTO
|--------------------------------------------------------------------------
|
| Campos que necesitamos del backend.
|
| is_active puede llegar como boolean
| o como 0/1 dependiendo de los casts
| configurados en el modelo Laravel.
|
*/

export interface Department {
  id: number;
  name: string;
  code: string;
  is_active: boolean | 0 | 1;
}

/*
|--------------------------------------------------------------------------
| RESPUESTA DE LARAVEL
|--------------------------------------------------------------------------
|
| DepartmentController::index() devuelve:
|
| {
|   data: [...]
| }
|
| No utiliza paginación actualmente.
|
*/

export interface DepartmentsResponse {
  data: Department[];
}

/*
|--------------------------------------------------------------------------
| SERVICIO DE DEPARTAMENTOS
|--------------------------------------------------------------------------
*/

@Injectable({
  providedIn: 'root',
})
export class DepartmentService {

  private readonly http = inject(HttpClient);

  /*
   * Utilizamos una URL relativa.
   *
   * Angular se comunica con Laravel
   * mediante el proxy ya configurado.
   */
  private readonly endpoint = '/api/departments';

  /*
   * Compartimos las cookies de sesión
   * establecidas con Laravel Sanctum.
   */
  private readonly requestOptions = {
    headers: new HttpHeaders({
      Accept: 'application/json',
    }),
    withCredentials: true,
  };

  /*
  |--------------------------------------------------------------------------
  | CONSULTAR DEPARTAMENTOS
  |--------------------------------------------------------------------------
  |
  | GET /api/departments
  |
  | Laravel realiza:
  |
  | - Autenticación mediante Sanctum.
  | - Validación de cuenta activa.
  | - Autorización DepartmentPolicy::viewAny.
  |
  | El servicio no transforma errores
  | 401 o 403 en listas vacías.
  |
  */

  list(): Observable<DepartmentsResponse> {
    return this.http.get<DepartmentsResponse>(
      this.endpoint,
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | CONSULTAR DEPARTAMENTOS ACTIVOS
  |--------------------------------------------------------------------------
  |
  | Este método está destinado al
  | formulario de creación de identidades.
  |
  | Los departamentos inactivos no deben
  | aparecer como opciones seleccionables.
  |
  | Aceptamos true o 1 para mantener
  | compatibilidad con los tipos de datos
  | que puede serializar Laravel.
  |
  | IMPORTANTE:
  |
  | Este filtro es únicamente para
  | presentar opciones válidas al usuario.
  |
  | Laravel vuelve a validar que
  | department_id pertenece a un
  | departamento activo antes de
  | crear la identidad.
  |
  */

  listActive(): Observable<Department[]> {
    return this.list().pipe(
      map((response) =>
        response.data.filter(
          (department) =>
            department.is_active === true ||
            department.is_active === 1
        )
      )
    );
  }
}
