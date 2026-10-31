import { inject, Injectable } from '@angular/core';

import {
  HttpClient,
  HttpHeaders,
  HttpParams,
} from '@angular/common/http';

import { Observable, map } from 'rxjs';

/*
|--------------------------------------------------------------------------
| YESIAM - RESOURCE SERVICE
|--------------------------------------------------------------------------
|
| FASE 21
|
| Catálogo de recursos protegidos:
|
| GET    /api/resources
| GET    /api/resources/{id}
| POST   /api/resources
| PATCH  /api/resources/{id}
| PATCH  /api/resources/{id}/deactivate
| GET    /api/resources/{id}/eligibility
| PUT    /api/resources/{id}/eligibility
| GET    /api/resources/{id}/approvers
| POST   /api/resources/{id}/approvers
| DELETE /api/resources/{id}/approvers/{user}
|
| Laravel autoriza cada operación con
| ResourcePolicy y registra la auditoría.
|
*/

export interface Resource {
  id: number;
  name: string;
  code: string;
  description: string | null;
  is_active: boolean;
  department_restricted: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface ResourcePayload {
  name: string;
  code: string;
  description: string | null;
}

export interface ResourceMutationResponse {
  message: string;
  data: Resource;
}

export interface ResourceDeactivationResponse {
  message: string;
  data: Resource;
  revoked_access_grants_count: number;
}

export interface ResourceEligibility {
  resource_id: number;
  resource_name: string;
  department_restricted: boolean;
  eligible_departments: {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
  }[];
}

export interface EligibilityUpdateResponse {
  message: string;
  data: {
    resource_id: number;
    department_restricted: boolean;
    department_ids: number[];
    changed: boolean;
  };
}

export interface ResourceApprover {
  id: number;
  name: string;
  email: string;
  status: string;
  assigned_at: string | null;
}

@Injectable({
  providedIn: 'root',
})
export class ResourceService {

  private readonly http = inject(HttpClient);

  private readonly endpoint = '/api/resources';

  private readonly requestOptions = {
    headers: new HttpHeaders({
      Accept: 'application/json',
    }),
    withCredentials: true,
  };

  /*
   * includeInactive solo tiene efecto para
   * quien administra recursos; Laravel lo
   * ignora para el resto.
   */
  list(includeInactive = false): Observable<Resource[]> {
    let params = new HttpParams();

    if (includeInactive) {
      params = params.set('include_inactive', '1');
    }

    return this.http.get<{ data: Resource[] }>(
      this.endpoint,
      {
        ...this.requestOptions,
        params,
      }
    ).pipe(
      map((response) => response.data)
    );
  }

  get(id: number): Observable<Resource> {
    return this.http.get<{ data: Resource }>(
      `${this.endpoint}/${id}`,
      this.requestOptions
    ).pipe(
      map((response) => response.data)
    );
  }

  create(payload: ResourcePayload): Observable<ResourceMutationResponse> {
    return this.http.post<ResourceMutationResponse>(
      this.endpoint,
      {
        name: payload.name,
        code: payload.code,
        description: payload.description,
      },
      this.requestOptions
    );
  }

  update(
    id: number,
    payload: ResourcePayload
  ): Observable<ResourceMutationResponse> {
    return this.http.patch<ResourceMutationResponse>(
      `${this.endpoint}/${id}`,
      {
        name: payload.name,
        code: payload.code,
        description: payload.description,
      },
      this.requestOptions
    );
  }

  deactivate(id: number): Observable<ResourceDeactivationResponse> {
    return this.http.patch<ResourceDeactivationResponse>(
      `${this.endpoint}/${id}/deactivate`,
      {},
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | ELEGIBILIDAD DEPARTAMENTAL
  |--------------------------------------------------------------------------
  |
  | restricted = false -> cualquier departamento.
  | restricted = true  -> solo los indicados.
  | restricted = true y lista vacía -> nadie.
  |
  */

  eligibility(id: number): Observable<ResourceEligibility> {
    return this.http.get<{ data: ResourceEligibility }>(
      `${this.endpoint}/${id}/eligibility`,
      this.requestOptions
    ).pipe(
      map((response) => response.data)
    );
  }

  updateEligibility(
    id: number,
    restricted: boolean,
    departmentIds: number[]
  ): Observable<EligibilityUpdateResponse> {
    return this.http.put<EligibilityUpdateResponse>(
      `${this.endpoint}/${id}/eligibility`,
      {
        department_restricted: restricted,
        department_ids: restricted ? departmentIds : [],
      },
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | RESPONSABLES DE APROBACIÓN
  |--------------------------------------------------------------------------
  */

  approvers(id: number): Observable<ResourceApprover[]> {
    return this.http.get<{ data: ResourceApprover[] }>(
      `${this.endpoint}/${id}/approvers`,
      this.requestOptions
    ).pipe(
      map((response) => response.data)
    );
  }

  addApprover(id: number, userId: number): Observable<unknown> {
    return this.http.post(
      `${this.endpoint}/${id}/approvers`,
      { user_id: userId },
      this.requestOptions
    );
  }

  removeApprover(id: number, userId: number): Observable<unknown> {
    return this.http.delete(
      `${this.endpoint}/${id}/approvers/${userId}`,
      this.requestOptions
    );
  }
}
