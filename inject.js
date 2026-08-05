(() => {
    const myServerObj = new URL(MY_SERVER)

    const rewrite = (oldUrl) => {
        const url = String(oldUrl)
        if (url.startsWith('/')) {
            return MY_SERVER + '/' + ORIGIN + url
        }
        if (!URL.canParse(url)) {
            return oldUrl
        }
        const urlObj = new URL(url)
        if (urlObj.protocol !== 'data:'
            && urlObj.protocol !== 'file:'
            && urlObj.hostname !== myServerObj.hostname) {
            return MY_SERVER + '/' + url
        }
        if (urlObj.protocol === 'data:'
            && urlObj.pathname.startsWith('text/javascript,')) {
            return encodeURI(rewriteJs(decodeURIComponent(url)))
        }
        return oldUrl
    }

    // deal with js remote import etc.
    const rewriteJs = (js) => {
        const r = /(['"])\s*(https?:\/\/.*?)\1/g
        return js.replace(r, (match, s1, s2) =>
            new URL(s2).hostname === myServerObj.hostname
                ? match : s1 + MY_SERVER + '/' + s2 + s1)
    }

    const doRewrite = (node) => {
        if (!node instanceof Node) {
            return
        }
        // force script reload
        if (node.tagName === 'SCRIPT' && !node._altered) {
            const parent = node.parentElement
            parent.removeChild(node)
            const script = node.cloneNode(true)
            // prevent recursion in mu observer
            script._altered = true
            if (node.src) {
                script.src = rewrite(node.src)
            }
            script.textContent = rewriteJs(node.textContent)
            parent.appendChild(script)
        } else if (node.src) {
            node.src = rewrite(node.src)
        }
        // imageset
        if (node.tagName === 'IMG' && node.srcset) {
            const srcset = node.srcset.split(',')
            for (let i in srcset) {
                srcset[i] = rewrite(srcset[i].trim())
            }
            node.srcset = srcset.join(', ')
        }
        if (node.href) {
            node.href = rewrite(node.href)
        }
        // form
        if (node.action) {
            node.action = rewrite(node.action)
        }
        // button, input
        if (node.formaction) {
            node.formaction = rewrite(node.formaction)
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[src],[href],[action],[formaction]')
            .forEach(doRewrite)
    })

    const realFetch = window.fetch
    window.fetch = async function (resource, options) {
        return await realFetch(rewrite(resource), options)
    }

    const realOpen = XMLHttpRequest.prototype.open
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        url = rewrite(url)
        return realOpen.apply(this, arguments)
    }

    if (!'MutationObserver' in globalThis) {
        return
    }

    // deal with lazy loading
    setInterval(() => {
        const list = document.querySelectorAll('img,iframe,video,audio')
        list.forEach(item => {
            const newSrc = rewrite(item.src)
            if (newSrc != item.src) {
                item.src = newSrc
            }
        })
    }, 1000)
    // const watchList = ['IMG', 'VIDEO', 'AUDIO', 'IFRAME']
    // const lazyObOption = {
    //     attributes: true,
    //     attributeFilter: ['src']
    // }
    // const lazyObserver = new MutationObserver((muList) => {
    //     for (const mutation of muList) {
    //         if (mutation.type !== 'attributes'
    //             || mutation.attributeName !== 'src') {
    //             continue
    //         }
    //         const newSrc = rewrite(mutation.target.src)
    //         if (newSrc != mutation.target.src) {
    //             mutation.target.src = newSrc
    //         }
    //     }
    // })
    // document.addEventListener('DOMContentLoaded', () => {
    //     document.querySelectorAll('img,iframe,video,audio')
    //         .forEach(item => {
    //             lazyObserver.observe(item, lazyObOption)
    //         })
    // })

    // rewrite links in dynamic elements
    const observer = new MutationObserver((muList) => {
        for (const mutation of muList) {
            if (mutation.type !== 'childList') {
                continue
            }
            mutation.addedNodes.forEach(item => {
                doRewrite(item)
                // if (watchList.includes(item.tagName)) {
                //     lazyObserver.observe(item, lazyObOption)
                // }
            })
        }
    })
    observer.observe(document.documentElement, {childList: true, subtree: true})
})()