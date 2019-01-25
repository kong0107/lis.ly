export const fetch2 = (input, init) =>
  new Promise((resolve, reject) =>
    fetch(input, init)
    .then(res => res.ok ? resolve(res) : reject(new ReferenceError(res.statusText)))
    .catch(reject)
  )
;

export const errorHandler = (...args) =>
    console.error(...args)
;
